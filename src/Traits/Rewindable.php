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

        // Get the changed attributes. In the saved event:
        // - For creates: getDirty() may have values before syncOriginal() is called
        // - For updates: getChanges() has the values (getDirty is empty because syncChanges was called)
        $changedAttributes = $this->getChanges() ?: $this->getDirty();

        // If there's no change, don't fire the event
        if (empty($changedAttributes) && ! $this->wasRecentlyCreated && $this->exists) {
            return;
        }

        // Filter out excluded attributes from changed attributes to see if there are any trackable changes
        if ($this->exists && ! $this->wasRecentlyCreated) {
            $trackableChanges = array_diff_key(
                $changedAttributes,
                array_flip($this->getExcludedRewindableAttributes())
            );

            // If only excluded attributes changed, don't fire the event
            if (empty($trackableChanges)) {
                return;
            }
        }

        // Determine event type if not explicitly provided.
        // Note: we don't use wasRecentlyCreated here because it persists on the
        // model instance, causing subsequent updates to also appear as "created".
        // The listener will determine Created vs Updated using the version number.
        if ($eventType === null) {
            $eventType = VersionEventType::Updated;
        }

        // Read metadata from the context singleton and clear it
        $meta = app(RewindContext::class)->flush();

        // Capture transient model state now so it survives serialization for queued listeners
        event(new RewindVersionCreating(
            model: $this,
            changes: $changedAttributes,
            wasRecentlyCreated: $this->wasRecentlyCreated,
            eventType: $eventType,
            meta: $meta,
        ));
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
        return $this->morphMany(RewindVersion::class, 'model');
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
