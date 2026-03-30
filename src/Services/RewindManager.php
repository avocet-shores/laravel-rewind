<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Contracts\RewindManagerInterface;
use AvocetShores\LaravelRewind\Exceptions\CurrentVersionColumnMissingException;
use AvocetShores\LaravelRewind\Exceptions\LaravelRewindException;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Support\SchemaHelper;
use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class RewindManager implements RewindManagerInterface
{
    public function __construct(
        protected StateBuilder $stateBuilder,
    ) {}

    /**
     * Rewind by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function rewind($model, int $steps = 1): int
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) - $steps;

        try {
            return $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, clamp to the lowest version
            $minVersion = $model->versions->min('version');

            return $this->goTo($model, $minVersion);
        }
    }

    /**
     * Fast-forward by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function fastForward($model, int $steps = 1): int
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) + $steps;

        try {
            return $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, clamp to the highest version
            $maxVersion = $model->versions->max('version');

            return $this->goTo($model, $maxVersion);
        }
    }

    /**
     * Jump directly to a specified version.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     */
    public function goTo($model, int $targetVersion): int
    {
        $this->assertRewindable($model);
        $this->eagerLoadVersions($model);

        // Validate the target version
        $targetModel = $model->versions->where('version', $targetVersion)->first();
        if (! $targetModel) {
            throw new VersionDoesNotExistException('The specified version does not exist.');
        }

        $model->fill(
            $this->buildAttributesForVersion($model, $targetVersion)
        );

        $this->updateModelVersionAndSave($model, $targetVersion);

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
