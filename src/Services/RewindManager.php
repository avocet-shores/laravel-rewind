<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Contracts\RewindManagerInterface;
use AvocetShores\LaravelRewind\Dto\VersionDiff;
use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Exceptions\CurrentVersionColumnMissingException;
use AvocetShores\LaravelRewind\Exceptions\LaravelRewindException;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Support\SchemaHelper;
use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RewindManager implements RewindManagerInterface
{
    public function __construct(
        protected StateBuilder $stateBuilder,
        protected RewindContext $rewindContext,
    ) {}

    /**
     * Set metadata to attach to the next version created.
     */
    public function withMeta(array $meta): void
    {
        $this->rewindContext->set($meta);
    }

    /**
     * Execute a callback with versioning disabled.
     * Model changes within the callback will not create version records.
     */
    public function withoutVersioning(callable $callback): mixed
    {
        $this->rewindContext->disableVersioning();

        try {
            return $callback();
        } finally {
            $this->rewindContext->enableVersioning();
        }
    }

    /**
     * Group multiple model changes into a single logical batch.
     * All versions created within the callback share a batch UUID.
     *
     * @return string The batch UUID
     *
     * @throws \LogicException If called while already inside a batch
     */
    public function batch(callable $callback): string
    {
        if ($this->rewindContext->getBatchUuid() !== null) {
            throw new \LogicException('Cannot nest Rewind::batch() calls.');
        }

        $uuid = (string) Str::uuid();
        $this->rewindContext->setBatchUuid($uuid);

        try {
            $callback();
        } finally {
            $this->rewindContext->clearBatch();
        }

        return $uuid;
    }

    /**
     * Non-destructive revert: creates a new version with the state from a previous version.
     * The full audit trail is preserved — the revert itself is a versioned event.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     */
    public function restore(Model $model, int $targetVersion): int
    {
        $this->assertRewindable($model);
        $this->eagerLoadVersions($model);

        // Validate target version exists
        $targetExists = $model->versions->where('version', $targetVersion)->first(); // @phpstan-ignore property.notFound
        if (! $targetExists) {
            throw new VersionDoesNotExistException('The specified version does not exist.');
        }

        // Get the attributes at the target version
        $attributes = $this->buildAttributesForVersion($model, $targetVersion);

        // Merge restored_from_version into any existing pending meta
        $existingMeta = $this->rewindContext->get();
        $this->rewindContext->set(array_merge($existingMeta, [
            'restored_from_version' => $targetVersion,
        ]));

        // Set event type override so the new version is marked as 'restored'
        $this->rewindContext->setEventTypeOverride(VersionEventType::Restored);

        // Force version creation even if attributes haven't changed
        $this->rewindContext->setForceVersion(true);

        // Fill and save — triggers normal Rewindable event hooks,
        // creating a new version through the standard pipeline
        $model->fill($attributes);
        $model->save();

        return $model->current_version; // @phpstan-ignore property.notFound
    }

    /**
     * Rewind by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function rewind(Model $model, int $steps = 1): int
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) - $steps;

        try {
            return $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, clamp to the lowest version
            $minVersion = $model->versions->min('version'); // @phpstan-ignore property.notFound

            return $this->goTo($model, $minVersion);
        }
    }

    /**
     * Fast-forward by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function fastForward(Model $model, int $steps = 1): int
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) + $steps;

        try {
            return $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, clamp to the highest version
            $maxVersion = $model->versions->max('version'); // @phpstan-ignore property.notFound

            return $this->goTo($model, $maxVersion);
        }
    }

    /**
     * Jump directly to a specified version.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     * @throws LockTimeoutException
     */
    public function goTo(Model $model, int $targetVersion): int
    {
        $this->assertRewindable($model);

        $lock = cache()->lock(
            sprintf('laravel-rewind-version-lock-%s-%s', $model->getTable(), $model->getKey()),
            config('rewind.lock_timeout', 10)
        );

        $lock->block(config('rewind.lock_wait', 20), function () use ($model, $targetVersion) {
            $this->eagerLoadVersions($model);

            // Validate the target version
            $targetModel = $model->versions->where('version', $targetVersion)->first(); // @phpstan-ignore property.notFound
            if (! $targetModel) {
                throw new VersionDoesNotExistException('The specified version does not exist.');
            }

            $model->fill(
                $this->buildAttributesForVersion($model, $targetVersion)
            );

            $this->updateModelVersionAndSave($model, $targetVersion);
        });

        return $targetVersion;
    }

    /**
     * Replicates the given model and fills it with the attributes from the specified version.
     *
     * @throws LaravelRewindException
     */
    public function cloneModel(Model $model, int $targetVersion): Model
    {
        $this->assertRewindable($model);
        $this->eagerLoadVersions($model);

        $attributes = $this->buildAttributesForVersion($model, $targetVersion);

        $newModel = $model->replicate(
            except: ['current_version']
        );
        $newModel->fill($attributes);
        $newModel->save();

        return $newModel;
    }

    /**
     * @throws LaravelRewindException
     */
    public function getVersionAttributes(Model $model, int $targetVersion): array
    {
        $this->assertRewindable($model);
        $this->eagerLoadVersions($model);

        return $this->buildAttributesForVersion($model, $targetVersion);
    }

    /**
     * Get the attributes of a model at a specific point in time.
     *
     * @throws LaravelRewindException
     */
    public function versionAt(Model $model, Carbon $timestamp): array
    {
        $this->assertRewindable($model);

        $versionModelClass = RewindVersion::resolveVersionModelClass();
        $version = $versionModelClass::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->where('created_at', '<=', $timestamp)
            ->orderByDesc('version')
            ->first();

        if (! $version) {
            throw new VersionDoesNotExistException('No version exists at or before the given timestamp.');
        }

        return $this->buildAttributesForVersion($model, $version->version);
    }

    /**
     * Compare two versions and return a structured diff.
     *
     * @throws LaravelRewindException
     */
    public function diff(Model $model, int $fromVersion, int $toVersion): VersionDiff
    {
        $fromAttrs = $this->getVersionAttributes($model, $fromVersion);
        $toAttrs = $this->getVersionAttributes($model, $toVersion);

        return VersionDiff::fromAttributes($fromAttrs, $toAttrs);
    }

    /**
     * Build an array of attributes representing the given version
     */
    protected function buildAttributesForVersion($model, int $targetVersion): array
    {
        $model->load('versions');
        $currentVersion = $this->determineCurrentVersion($model);

        $currentAttributes = Arr::except(
            $model->attributesToArray(),
            $model->getExcludedRewindableAttributes()
        );

        return $this->stateBuilder->buildStateForVersion(
            versions: $model->versions,
            currentVersion: $currentVersion,
            targetVersion: $targetVersion,
            currentAttributes: $currentAttributes,
        );
    }

    /**
     * Update the model's current_version to the specified version without triggering Rewind events
     */
    protected function updateModelVersionAndSave($model, int $version): void
    {
        if (! SchemaHelper::modelHasCurrentVersionColumn($model)) {
            return;
        }

        $model->disableRewindEvents();

        $model->forceFill([
            'current_version' => $version,
        ])->saveQuietly();

        $model->enableRewindEvents();
    }

    /**
     * Determine the model's current version.
     *
     * If a current_version column exists, return it.
     * Otherwise, fallback to the highest version from the versions table (a best guess).
     */
    protected function determineCurrentVersion($model): int
    {
        if (SchemaHelper::modelHasCurrentVersionColumn($model)) {
            // Use the stored current_version, defaulting to 0
            return $model->current_version ?? 0;
        }

        // If there's no current_version column, fallback to the highest known version
        return $model->versions()->max('version') ?? 0;
    }

    /**
     * Ensure the model uses the Rewindable trait.
     *
     * @throws ModelNotRewindableException
     * @throws CurrentVersionColumnMissingException
     */
    protected function assertRewindable($model): void
    {
        if (collect(class_uses_recursive($model::class))->doesntContain(Rewindable::class)) {
            throw new ModelNotRewindableException(sprintf('%s must use the Rewindable trait in order to access Rewind functionality.', $model::class));
        }

        if (! SchemaHelper::modelHasCurrentVersionColumn($model)) {
            throw new CurrentVersionColumnMissingException($model);
        }
    }

    protected function eagerLoadVersions(Model $model): void
    {
        $model->load('versions');
    }
}
