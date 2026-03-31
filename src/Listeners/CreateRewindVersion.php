<?php

namespace AvocetShores\LaravelRewind\Listeners;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Events\RewindVersionCreated;
use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Events\RewindVersionLockTimeout;
use AvocetShores\LaravelRewind\Exceptions\LockTimeoutRewindException;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\StateBuilder;
use AvocetShores\LaravelRewind\Services\VersionPruner;
use AvocetShores\LaravelRewind\Support\SchemaHelper;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateRewindVersion
{
    public function __construct() {}

    public function handle(RewindVersionCreating $event): void
    {
        $model = $event->model;

        // Use changes and wasRecentlyCreated from the event, which were captured at dispatch
        // time and survive serialization. This is critical for queued listeners where the
        // model's transient state (getChanges/getDirty/wasRecentlyCreated) is lost.
        $dirty = $event->changes;
        $wasRecentlyCreated = $event->wasRecentlyCreated;

        $lock = cache()->lock(
            sprintf('laravel-rewind-version-lock-%s-%s', $model->getTable(), $model->getKey()),
            config('rewind.lock_timeout', 10)
        );

        $lockAcquired = false;

        try {
            $lock->block(config('rewind.lock_wait', 20));
            $lockAcquired = true;

            // Re-check that something changed (edge case: might be no changes after all)
            if (empty($dirty) && ! $wasRecentlyCreated && $model->exists) {
                return;
            }

            // Determine the new version number
            $nextVersion = ($model->versions()->max('version') ?? 0) + 1; // @phpstan-ignore method.notFound

            // Override event type to Created for the first version of a model,
            // regardless of what the trait passed (wasRecentlyCreated is unreliable).
            $eventType = $event->eventType;
            $meta = $event->meta;
            if ($nextVersion === 1) {
                $eventType = VersionEventType::Created;
            }

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
                    ($wasRecentlyCreated && empty($originalValue))
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

            // Capture values from the model and event before entering the transaction closure,
            // so PHPStan can resolve trait methods on the concrete model type.
            $morphClass = $model->getMorphClass();
            $modelKey = $model->getKey();
            $trackUser = $model->getRewindTrackUser(); // @phpstan-ignore method.notFound
            $hasCurrentVersionColumn = SchemaHelper::modelHasCurrentVersionColumn($model);

            // Wrap version creation, model update, and auto-prune in a transaction
            // so that a crash between steps doesn't leave current_version out of sync.
            $connection = (new RewindVersion)->getConnectionName();

            $rewindVersion = DB::connection($connection)->transaction(function () use (
                $model, $nextVersion, $oldValues, $newValues, $isSnapshot,
                $morphClass, $modelKey, $trackUser, $hasCurrentVersionColumn,
                $eventType, $meta
            ) {
                // Create the RewindVersion record
                $rewindVersion = RewindVersion::create([
                    'model_type' => $morphClass,
                    'model_id' => $modelKey,
                    'version' => $nextVersion,
                    config('rewind.user_id_column') => $trackUser,
                    'old_values' => $oldValues ?: null,
                    'new_values' => $newValues ?: null,
                    'is_snapshot' => $isSnapshot,
                    'event_type' => $eventType,
                    'meta' => $meta ?: null,
                ]);

                // Update the model's current_version
                if ($hasCurrentVersionColumn) {
                    $model->disableRewindEvents(); // @phpstan-ignore method.notFound
                    $model->forceFill([
                        'current_version' => $nextVersion,
                    ])->saveQuietly();
                    $model->enableRewindEvents(); // @phpstan-ignore method.notFound
                }

                // Auto-prune if a max versions cap is configured
                $this->autoPruneIfNeeded($model);

                return $rewindVersion;
            });

            // Fire the "RewindVersionCreated" event outside the transaction
            event(new RewindVersionCreated($model, $rewindVersion));

        } catch (LockTimeoutException $e) {
            // If we cannot acquire a lock, something is most likely wrong with the environment
            $this->handleLockTimeoutException($model, $e, $dirty);

            return;
        } finally {
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    protected function handleLockTimeoutException($model, LockTimeoutException $e, array $changes = []): void
    {

        Log::error(sprintf(
            'Failed to acquire lock for RewindVersion creation on %s:%s, your versions may be out of sync.',
            get_class($model),
            $model->getKey(),
        ), [
            'model' => get_class($model),
            'model_key' => $model->getKey(),
            'changes' => $changes,
        ]);

        $behavior = config('rewind.on_lock_timeout', 'log');

        if (in_array($behavior, ['event', 'throw'], true)) {
            event(new RewindVersionLockTimeout($model, $e, $changes));
        }

        if ($behavior === 'throw') {
            throw new LockTimeoutRewindException(
                sprintf(
                    'Lock timeout while creating RewindVersion for %s:%s',
                    get_class($model),
                    $model->getKey(),
                ),
                previous: $e,
            );
        }
    }

    protected function rebuildHeadVersion($model): array
    {
        $headVersion = $model->versions()->max('version') ?? 0;

        return app(StateBuilder::class)->reconstructStateAtVersion(
            $model->getMorphClass(),
            $model->getKey(),
            $headVersion,
        );
    }

    protected function computeTrackableAttributes($model): array
    {
        $attributes = array_keys(Arr::except(
            $model->getAttributes(),
            $model->getExcludedRewindableAttributes()
        ));

        // Ensure we track soft deletes (unless explicitly excluded)
        if ($model->hasSoftDeletes()) {
            $deletedAtColumn = $model->getDeletedAtColumn();
            if (! in_array($deletedAtColumn, $model->getExcludedRewindableAttributes())) {
                $attributes[] = $deletedAtColumn;
            }
        }

        return array_unique($attributes);
    }

    protected function handleDateAttribute($value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : $value;
    }

    protected function isNotHead($model, int $nextVersion): bool
    {
        return $model->current_version && $model->current_version !== ($nextVersion - 1);
    }

    protected function autoPruneIfNeeded(Model $model): void
    {
        $maxVersions = method_exists($model, 'maxRewindVersions')
            ? $model->maxRewindVersions()
            : null;

        $maxVersions = $maxVersions ?? config('rewind.max_versions');

        if ($maxVersions === null) {
            return;
        }

        $snapshotInterval = (int) config('rewind.snapshot_interval', 10);

        $versionCount = RewindVersion::where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->count();

        if ($versionCount <= $maxVersions + $snapshotInterval) {
            return;
        }

        app(VersionPruner::class)->pruneForModel($model, (int) $maxVersions);
    }
}
