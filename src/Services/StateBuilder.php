<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use Illuminate\Support\Collection;

/**
 * @internal Not part of the public API. Subject to change without notice.
 */
class StateBuilder
{
    public function __construct(
        protected ApproachEngine $approachEngine,
    ) {}

    /**
     * Reconstruct the full attribute state at a given version.
     *
     * Uses the ApproachEngine to determine the optimal path (direct stepping
     * or jumping from the nearest snapshot in either direction), then replays
     * diffs accordingly.
     *
     * @param  Collection  $versions  The full set of RewindVersion records for this model instance.
     * @param  int  $currentVersion  The version to start from (used for direct stepping cost calculation).
     * @param  int  $targetVersion  The version to reconstruct.
     * @param  array  $currentAttributes  The model's current attributes (used when stepping directly without a snapshot).
     */
    public function buildStateForVersion(
        Collection $versions,
        int $currentVersion,
        int $targetVersion,
        array $currentAttributes = [],
    ): array {
        $approach = $this->approachEngine->run($versions, $currentVersion, $targetVersion);

        return match ($approach->method) {
            ApproachMethod::None => $currentAttributes,
            ApproachMethod::Direct => $this->buildFromDiffs(
                versions: $versions,
                startAttributes: $currentAttributes,
                fromVersion: $currentVersion,
                targetVersion: $targetVersion,
            ),
            ApproachMethod::From_Snapshot => $this->buildFromDiffs(
                versions: $versions,
                startAttributes: $approach->snapshot->new_values ?? [],
                fromVersion: $approach->snapshot->version,
                targetVersion: $targetVersion,
            ),
        };
    }

    /**
     * Batch-reconstruct state for multiple models at their respective target versions.
     *
     * Loads all version records in a single query and reconstructs each model
     * independently. Returns an associative array keyed by model_id.
     *
     * @param  array<int|string, int>  $modelVersionMap  [model_id => target_version, ...]
     * @return array<int|string, array> [model_id => attributes, ...]
     */
    public function reconstructMultipleModelsAtVersions(array $modelVersionMap, string $modelType): array
    {
        if (empty($modelVersionMap)) {
            return [];
        }

        $versionModelClass = RewindVersion::resolveVersionModelClass();

        // Load all versions for all requested models in one query
        $allVersions = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->whereIn('model_id', array_keys($modelVersionMap))
            ->orderBy('version')
            ->get()
            ->groupBy('model_id');

        $results = [];

        foreach ($modelVersionMap as $modelId => $targetVersion) {
            $versions = $allVersions->get($modelId, collect());

            if ($versions->isEmpty()) {
                continue;
            }

            $results[$modelId] = $this->buildStateForVersion(
                versions: $versions,
                currentVersion: 0,
                targetVersion: $targetVersion,
                currentAttributes: [],
            );
        }

        return $results;
    }

    /**
     * Reconstruct state at a target version by querying the database directly.
     *
     * This is the entry point for callers that don't have an eager-loaded
     * collection or a model instance (e.g., the pruner, the listener).
     */
    public function reconstructStateAtVersion(string $modelType, mixed $modelId, int $targetVersion): array
    {
        $versionModelClass = RewindVersion::resolveVersionModelClass();
        $versions = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->orderBy('version')
            ->get();

        // We start from version 0 with empty attributes so the engine picks
        // the optimal snapshot and replays from there.
        return $this->buildStateForVersion(
            versions: $versions,
            currentVersion: 0,
            targetVersion: $targetVersion,
            currentAttributes: [],
        );
    }

    /**
     * Step through version diffs from one version to another, applying
     * old_values (backward) or new_values (forward) as appropriate.
     *
     * Iterates through the actual version records in the collection rather
     * than sequential integers, so gaps from pruned versions are handled
     * correctly.
     */
    protected function buildFromDiffs(
        Collection $versions,
        array $startAttributes,
        int $fromVersion,
        int $targetVersion,
    ): array {
        $attributes = $startAttributes;

        if ($fromVersion > $targetVersion) {
            // Step downward: apply old_values to reverse each diff
            $steppingVersions = $versions
                ->where('version', '>', $targetVersion)
                ->where('version', '<=', $fromVersion)
                ->sortByDesc('version');

            foreach ($steppingVersions as $versionRec) {
                $attributes = array_merge($attributes, $versionRec->old_values ?? []);
            }
        } else {
            // Step upward: apply new_values for each diff
            $steppingVersions = $versions
                ->where('version', '>', $fromVersion)
                ->where('version', '<=', $targetVersion)
                ->sortBy('version');

            foreach ($steppingVersions as $versionRec) {
                $attributes = array_merge($attributes, $versionRec->new_values ?? []);
            }
        }

        return $attributes;
    }
}
