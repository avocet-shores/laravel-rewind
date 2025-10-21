<?php

namespace AvocetShores\LaravelRewind\Traits;

use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Models\RewindVersion;
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

        static::deleting(function ($model) {
            // Only dispatch a rewind event if the model is not force deleting
            if ($model->hasSoftDeletes() && ! $model->isForceDeleting()) {

                // Ensure `deleted_at` shows up as dirty
                $model->forceFill([$model->getDeletedAtColumn() => $model->freshTimestampString()]);

                $model->dispatchRewindEvent();
            }
        });

        static::deleted(function ($model) {
            // If the model is force deleting or does not use soft deletes, delete all versions
            if (! $model->hasSoftDeletes() || $model->isForceDeleting()) {
                $model->versions()->delete();
            }
        });
    }

    public function hasSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this));
    }

    protected function dispatchRewindEvent(): void
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

        event(new RewindVersionCreating($this));
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
            10
        )->block(5, function () {

            // If versions already exist, skip
            if ($this->versions()->exists()) {
                return;
            }

            $this->versions()->create([
                'model_id' => $this->getKey(),
                'model_type' => $this->getMorphClass(),
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
}
