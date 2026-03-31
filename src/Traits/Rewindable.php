<?php

namespace AvocetShores\LaravelRewind\Traits;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\RewindContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Trait Rewindable
 *
 * When added to an Eloquent model, this trait will:
 *  - Listen to model events (creating/updating/deleting).
 *  - Capture "old" and "new" values for trackable attributes.
 *  - Store those values in a "rewind_versions" table with a version number.
 *  - Provide a relationship to access the version records as an audit log.
 */
trait Rewindable
{
    protected bool $disableRewindEvents = false;

    /**
     * Define any additional attributes to exclude from rewind's versions.
     * The default exclusion list includes timestamps, primary key, and current_version.
     */
    public static function excludedFromVersioning(): array
    {
        return [];
    }

    public function getExcludedRewindableAttributes(): array
    {
        return array_merge([
            $this->getKeyName(),
            'created_at',
            'updated_at',
            'current_version',
        ], $this->excludedFromVersioning());
    }

    /**
     * Boot the trait. Registers relevant event listeners.
     */
    public static function bootRewindable(): void
    {
        static::saved(function ($model) {
            $model->dispatchRewindEvent();
        });

        static::deleted(function ($model) {
            if ($model->hasSoftDeletes() && ! $model->isForceDeleting()) {
                // Soft delete: dispatch rewind event after Eloquent has set deleted_at,
                // so the version records the exact timestamp stored in the database.
                // runSoftDelete() calls syncOriginalAttributes() which syncs the original
                // to match the current value, losing the dirty state. We restore it by
                // temporarily clearing deleted_at, syncing original to null, then setting
                // it back so getDirty() and getOriginal() reflect the actual change.
                $deletedAtColumn = $model->getDeletedAtColumn();
                $deletedAt = $model->getAttribute($deletedAtColumn);
                $model->setAttribute($deletedAtColumn, null);
                $model->syncOriginalAttributes([$deletedAtColumn]);
                $model->setAttribute($deletedAtColumn, $deletedAt);
                $model->dispatchRewindEvent(VersionEventType::Deleted);
                // Restore proper state so subsequent operations (e.g. restore) see
                // the correct original deleted_at value.
                $model->syncOriginalAttributes([$deletedAtColumn]);
            } else {
                // Force delete or no soft deletes: remove all versions
                $model->versions()->delete();
            }
        });
    }

    public function hasSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this));
    }

    protected function dispatchRewindEvent(?VersionEventType $eventType = null): void
    {
        // If the model signals it does not want Rewindable events, skip
        if (! empty($this->disableRewindEvents)) {
            return;
        }

        $context = app(RewindContext::class);

        // Read force version flag early so it's consumed even if we return early
        $forceVersion = $context->flushForceVersion();

        // Get the changed attributes. In the saved event:
        // - For creates: getDirty() may have values before syncOriginal() is called
        // - For updates: getChanges() has the values (getDirty is empty because syncChanges was called)
        $changedAttributes = $this->getChanges() ?: $this->getDirty();

        // If there's no change, don't fire the event (unless forced, e.g. by restore())
        if (! $forceVersion && empty($changedAttributes) && ! $this->wasRecentlyCreated && $this->exists) {
            return;
        }

        // Filter out excluded attributes from changed attributes to see if there are any trackable changes
        if (! $forceVersion && $this->exists && ! $this->wasRecentlyCreated) {
            $trackableChanges = array_diff_key(
                $changedAttributes,
                array_flip($this->getExcludedRewindableAttributes())
            );

            // If only excluded attributes changed, don't fire the event
            if (empty($trackableChanges)) {
                return;
            }
        }

        // If inside an amendCurrentVersion() callback, fold changes into
        // the current version record instead of creating a new one.
        // Forced versions (e.g. restore) always get a new version.
        if (! $forceVersion && $this->exists && $context->isAmending()) {
            $this->amendCurrentVersionRecord($changedAttributes);

            return;
        }

        // Determine event type if not explicitly provided.
        // We default to null here than checking wasRecentlyCreated, because
        // wasRecentlyCreated stays true on the model instance after the initial create,
        // causing subsequent updates on the same object to also appear as "created".
        // The listener determines Created vs Updated using the version number instead.
        if ($eventType === null) {
            $eventType = VersionEventType::Updated;
        }

        // Read metadata and overrides from the context singleton
        $meta = $context->flush();
        $batchUuid = $context->getBatchUuid();

        $eventTypeOverride = $context->flushEventTypeOverride();
        if ($eventTypeOverride !== null) {
            $eventType = $eventTypeOverride;
        }

        // Capture transient model state now so it survives serialization for queued listeners
        event(new RewindVersionCreating(
            model: $this,
            changes: $changedAttributes,
            wasRecentlyCreated: $this->wasRecentlyCreated,
            eventType: $eventType,
            meta: $meta,
            batchUuid: $batchUuid,
        ));
    }

    /**
     * Amend the current version record with changed attributes instead of
     * creating a new version. Adds any newly changed attributes to both
     * old_values and new_values on the existing version record.
     */
    protected function amendCurrentVersionRecord(array $changedAttributes): void
    {
        $currentVersion = $this->versions()->orderByDesc('version')->first(); // @phpstan-ignore method.notFound

        if (! $currentVersion) {
            return;
        }

        $excludedAttributes = $this->getExcludedRewindableAttributes();
        $oldValues = $currentVersion->old_values ?? [];
        $newValues = $currentVersion->new_values ?? [];

        foreach ($changedAttributes as $attribute => $value) {
            if (in_array($attribute, $excludedAttributes)) {
                continue;
            }

            // If this attribute isn't already in old_values, add the value
            // from before this save (the original value from the model).
            if (! array_key_exists($attribute, $oldValues)) {
                $oldValues[$attribute] = $this->handleDateAttributeForAmend($this->getOriginal($attribute));
            }

            // Always update new_values to reflect the latest state
            $newValues[$attribute] = $this->handleDateAttributeForAmend($this->getAttribute($attribute));
        }

        // Recalculate state transitions for the amended version
        $stateTransitions = $currentVersion->state_transitions ?? [];
        $stateFields = $this->getRewindStateFields();
        foreach ($stateFields as $field) {
            if (array_key_exists($field, $newValues)) {
                $stateTransitions[$field] = [
                    // Preserve original 'from' if this field was already tracked
                    'from' => $stateTransitions[$field]['from'] ?? ($oldValues[$field] ?? null),
                    'to' => $newValues[$field],
                ];
            }
        }

        $currentVersion->update([
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'state_transitions' => $stateTransitions ?: null,
        ]);
    }

    protected function handleDateAttributeForAmend(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : $value;
    }

    /**
     * Create a v1 snapshot of the model's current state if no versions exist.
     *
     * @throws LockTimeoutException
     */
    public function initVersion(): void
    {
        cache()->lock(
            sprintf('laravel-rewind-version-lock-%s-%s', $this->getTable(), $this->getKey()),
            config('rewind.lock_timeout', 10)
        )->block(config('rewind.lock_wait', 20), function () {

            // If versions already exist, skip
            if ($this->versions()->exists()) {
                return;
            }

            $this->versions()->create([
                'model_id' => $this->getKey(),
                'model_type' => $this->getMorphClass(),
                config('rewind.user_id_column') => $this->getRewindTrackUser(),
                'old_values' => [],
                'new_values' => $this->getAttributes(),
                'version' => 1,
                'is_snapshot' => true,
            ]);
        });
    }

    /**
     * A hasMany relationship to the version records.
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(RewindVersion::resolveVersionModelClass(), 'model');
    }

    /**
     * Get the user ID if tracking is enabled, otherwise null.
     *
     * @return int|string|null
     */
    public function getRewindTrackUser()
    {
        if (! config('rewind.track_user')) {
            return null;
        }

        return optional(Auth::user())->getKey();
    }

    public function disableRewindEvents(): void
    {
        $this->disableRewindEvents = true;
    }

    public function enableRewindEvents(): void
    {
        $this->disableRewindEvents = false;
    }

    /**
     * Get the fields designated as state fields for transition tracking.
     * Override by defining a $rewindStateFields property on your model.
     */
    public function getRewindStateFields(): array
    {
        return property_exists($this, 'rewindStateFields')
            ? $this->rewindStateFields
            : [];
    }

    /**
     * Get the state transition history for a specific field.
     */
    public function stateHistory(string $field): \Illuminate\Support\Collection
    {
        return $this->versions() // @phpstan-ignore method.notFound
            ->whereStateChanged($field)
            ->orderBy('version')
            ->get()
            ->map(function (RewindVersion $version) use ($field) {
                $transition = $version->state_transitions[$field] ?? null;

                return $transition ? [
                    'version' => $version->version,
                    'from' => $transition['from'],
                    'to' => $transition['to'],
                    'created_at' => $version->created_at,
                ] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Get the maximum number of rewind versions to keep for this model.
     * Override by defining a static $maxRewindVersions property on your model.
     */
    public static function maxRewindVersions(): ?int
    {
        return property_exists(static::class, 'maxRewindVersions')
            ? static::$maxRewindVersions
            : null;
    }
}
