<?php

namespace AvocetShores\LaravelRewind\Listeners;

use AvocetShores\LaravelRewind\Events\RewindVersionCreated;
use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CreateRewindVersion
{
    public function __construct() {}

    public function handle(RewindVersionCreating $event): void
    {
        $model = $event->model;

        $lock = cache()->lock(
            sprintf('laravel-rewind-version-lock-%s-%s', $model->getTable(), $model->getKey()),
            config('rewind.lock_timeout', 10)
        );

        try {
            $lock->block(config('rewind.lock_wait', 20));

            // Re-check that something is dirty (edge case: might be no changes after all)
            $dirty = $model->getDirty();
            if (empty($dirty) && ! $model->wasRecentlyCreated && $model->exists) {
                return;
            }

            // Determine the new version number
            $nextVersion = ($model->versions()->max('version') ?? 0) + 1;

            $oldValues = [];
            $newValues = [];

            // If our current version is not the head, we need to rebuild the head record, then store all of its trackable attributes as old_values.
            // We then store the new values as the current model attributes, and set it to be a snapshot
            $isSnapshot = false;
            if ($this->isNotHead($model, $nextVersion)) {
                $isSnapshot = true;
                $oldValues = $this->rebuildHeadVersion($model);
            }

            $attributesToTrack = $this->computeTrackableAttributes($model);

            foreach ($attributesToTrack as $attribute) {
                // if we forced a rebuild, oldValues might already contain the old attribute
                $originalValue = array_key_exists($attribute, $oldValues)
                    ? $oldValues[$attribute]
                    : $model->getOriginal($attribute);

                if (
                    ($model->wasRecentlyCreated && empty($originalValue))
                    || ! $model->exists
                    || array_key_exists($attribute, $dirty)
                ) {
                    $oldValues[$attribute] = $this->handleDateAttribute($originalValue);
                    $newValues[$attribute] = $this->handleDateAttribute($model->getAttribute($attribute));
                }
            }

            // If there's truly nothing to store, bail
            if (count($oldValues) === 0 && count($newValues) === 0) {
                return;
            }

            // Check if the snapshot interval triggers a mandatory snapshot
            $interval = config('rewind.snapshot_interval', 10);
            if (! $isSnapshot) {
                $isSnapshot = ($nextVersion % $interval === 0) || $nextVersion === 1;
            }

            if ($isSnapshot) {
                // We'll store a full snapshot of trackable attributes
                $allAttributes = $model->getAttributes();
                $newValues = Arr::only($allAttributes, $attributesToTrack);
            }

            // Create the RewindVersion record
            $rewindVersion = RewindVersion::create([
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'version' => $nextVersion,
                config('rewind.user_id_column') => $model->getRewindTrackUser(),
                'old_values' => $oldValues ?: null,
                'new_values' => $newValues ?: null,
                'is_snapshot' => $isSnapshot,
            ]);

            // Update the model's current_version
            if ($this->modelHasCurrentVersionColumn($model)) {
                $model->disableRewindEvents();

                $model->forceFill([
                    'current_version' => $nextVersion,
                ])->save();

                $model->enableRewindEvents();
            }

            // Fire the "RewindVersionCreated" event
            event(new RewindVersionCreated($model, $rewindVersion));

        } catch (LockTimeoutException) {
            // If we cannot acquire a lock, something is most likely wrong with the environment
            $this->handleLockTimeoutException($model);

            return;
        } finally {
            $lock->release();
        }
    }

    protected function handleLockTimeoutException($model): void
    {
        // Just log for now, but need to consider remediation options for this edge case.
        Log::error(sprintf(
            'Failed to acquire lock for RewindVersion creation on %s:%s, your versions may be out of sync.',
            get_class($model),
            $model->getKey(),
        ), [
            'model' => get_class($model),
            'model_key' => $model->getKey(),
            'changes' => $model->getDirty(),
        ]);
    }

    protected function rebuildHeadVersion($model): array
    {
        $data = [];
        $lastSnapshot = $model->versions()
            ->where('is_snapshot', true)
            ->latest('version')
            ->first();

        if ($lastSnapshot) {
            $data = $lastSnapshot->new_values;
        }

        // Loop through all versions since the last snapshot
        $model->versions()
            ->where('version', '>', $lastSnapshot?->version ?? 0)
            ->orderBy('version')
            ->each(function ($version) use (&$data) {
                $data = array_merge($data, $version->new_values);
            });

        return $data;
    }

    protected function computeTrackableAttributes($model): array
    {
        $attributes = array_keys(Arr::except(
            $model->getAttributes(),
            $model->getExcludedRewindableAttributes()
        ));

        // Ensure we track soft deletes
        if ($model->hasSoftDeletes()) {
            $attributes[] = $model->getDeletedAtColumn();
        }

        return array_unique($attributes);
    }

    protected function handleDateAttribute($value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : $value;
    }

    protected function modelHasCurrentVersionColumn($model): bool
    {
        return $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), 'current_version');
    }

    protected function isNotHead($model, int $nextVersion): bool
    {
        return $model->current_version && $model->current_version !== ($nextVersion - 1);
    }
}
