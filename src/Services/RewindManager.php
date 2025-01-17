<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use AvocetShores\LaravelRewind\Exceptions\LaravelRewindException;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class RewindManager
{
    public function __construct(
        protected ApproachEngine $approachEngine,
    ) {}

    /**
     * Rewind by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function rewind($model, int $steps = 1): void
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) - $steps;

        try {
            $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, just go to the lowest version
            $this->goTo($model, $model->versions->min('version'));
        }
    }

    /**
     * Fast-forward by a specified number of steps.
     *
     * @throws LaravelRewindException
     */
    public function fastForward($model, int $steps = 1): void
    {
        $this->assertRewindable($model);

        $targetVersion = $this->determineCurrentVersion($model) + $steps;

        try {
            $this->goTo($model, $targetVersion);
        } catch (VersionDoesNotExistException) {
            // If the target version doesn't exist, just go to the highest version
            $this->goTo($model, $model->versions->max('version'));
        }
    }

    /**
     * Jump directly to a specified version.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     */
    public function goTo($model, int $targetVersion): void
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

        // First, determine the fastest approach
        $approach = $this->approachEngine->run($model, $currentVersion, $targetVersion);

        return match ($approach->method) {
            ApproachMethod::None => $model->toArray(),
            ApproachMethod::Direct => $this->buildFromDiffs(
                model: $model,
                currentVersion: $currentVersion,
                targetVersion: $targetVersion
            ),
            ApproachMethod::From_Snapshot => $this->buildFromDiffs(
                model: $model,
                currentVersion: $approach->snapshot->version,
                targetVersion: $targetVersion,
                snapshot: $approach->snapshot
            ),
        };
    }

    protected function buildFromDiffs($model, int $currentVersion, int $targetVersion, ?RewindVersion $snapshot = null): array
    {
        $attributes = is_null($snapshot) ?
            $model->attributesToArray() :
            $snapshot->new_values ?? [];

        // Remove any attributes that are excluded
        $attributes = Arr::except($attributes, $model->getExcludedRewindableAttributes());

        if ($currentVersion > $targetVersion) {
            // Step downward from currentVersion until targetVersion
            for ($ver = $currentVersion; $ver > $targetVersion; $ver--) {
                $versionRec = $model->versions
                    ->where('version', $ver)
                    ->first();

                // If there's no partial diff for $ver (e.g. it doesn't exist), skip
                if (! $versionRec) {
                    continue;
                }

                // Reverse the partial diff by applying "old_values"
                $attributes = array_merge($attributes, $versionRec->old_values);
            }
        } else {
            // Step upward from currentVersion+1 until targetVersion
            for ($ver = $currentVersion + 1; $ver <= $targetVersion; $ver++) {
                $versionRec = $model->versions
                    ->where('version', $ver)
                    ->first();

                // If there's no partial diff for $ver (e.g. if it was a snapshot or doesn't exist), skip
                if (! $versionRec) {
                    continue;
                }

                // Apply the partial diff
                $attributes = array_merge($attributes, $versionRec->new_values);
            }
        }

        return $attributes;
    }

    /**
     * Update the model's current_version to the specified version without triggering Rewind events
     */
    protected function updateModelVersionAndSave($model, int $version): void
    {
        if (! $this->modelHasCurrentVersionColumn($model)) {
            return;
        }

        $model->disableRewindEvents();

        $model->current_version = $version;
        $model->save();

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
        if ($this->modelHasCurrentVersionColumn($model)) {
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
     */
    protected function assertRewindable($model): void
    {
        if (collect(class_uses_recursive($model::class))->doesntContain(Rewindable::class)) {
            throw new ModelNotRewindableException('Model must use the Rewindable trait to be rewound.');
        }
    }

    protected function eagerLoadVersions(Model $model): void
    {
        $model->load('versions');
    }

    /**
     * Check if the model's table has a 'current_version' column.
     */
    protected function modelHasCurrentVersionColumn($model): bool
    {
        return Schema::connection($model->getConnectionName())
            ->hasColumn($model->getTable(), 'current_version');
    }
}
