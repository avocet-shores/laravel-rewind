<?php

namespace AvocetShores\LaravelRewind\Traits;

use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            $model->dispatchRewindEvent();
        });
    }

    protected function dispatchRewindEvent(): void
    {
        // If the model signals it does not want Rewindable events, skip
        if (! empty($this->disableRewindEvents)) {
            return;
        }

        // If there's no change, don't fire the event
        if (empty($this->getDirty()) && ! $this->wasRecentlyCreated && $this->exists) {
            return;
        }

        event(new RewindVersionCreating($this));
    }

    /**
     * A hasMany relationship to the version records.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RewindVersion::class, 'model_id')
            ->where('model_type', static::class);
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
