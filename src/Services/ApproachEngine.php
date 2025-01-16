<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Dto\ApproachPlan;
use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use Illuminate\Database\Eloquent\Model;

class ApproachEngine
{
    /**
     * Decide how to reach the target version with the fewest partial diffs:
     * 1) Directly stepping backward from current (if target < currentVersion).
     * 2) Directly stepping forward from current (if target > currentVersion).
     * 3) Jumping to the nearest snapshot behind/above the target and replaying diffs.
     * 4) Similar logic for an overshoot snapshot, then stepping backward if that is somehow shorter.
     *
     * This returns an ApproachPlan describing the best approach and the intermediate snapshot (if any).
     */
    public function run(Model $model, int $currentVersion, int $targetVersion): ApproachPlan
    {
        // If currentVersion == targetVersion, there’s nothing to do.
        if ($currentVersion === $targetVersion) {
            return new ApproachPlan(
                method: ApproachMethod::None,
                cost: 0,
            );
        }

        // Load versions if not already loaded
        if (! $model->relationLoaded('versions')) {
            $model->load('versions');
        }

        // Count partial diffs for direct forward/backward from currentVersion.
        $directCost = $this->countPartialDiffs($model, min($currentVersion, $targetVersion), max($currentVersion, $targetVersion));

        // Find nearest snapshot behind (or equal to) the target if we want to replay forward from a snapshot.
        $snapshotBehind = $this->findNearestSnapshotBehind($model, $targetVersion);
        $snapshotBehindCost = null;
        if ($snapshotBehind) {
            $snapshotVersion = $snapshotBehind->version;
            // Jumping to the snapshot counts as 1 step/cost
            $snapshotBehindCost = 1 + $this->countPartialDiffs($model, $snapshotVersion, $targetVersion);
        }

        // Find nearest snapshot ahead of the target to overshoot and then step backward.
        $snapshotAhead = $this->findNearestSnapshotAhead($model, $targetVersion);
        $snapshotAheadCost = null;
        if ($snapshotAhead) {
            $snapshotAheadCost = 1 + $this->countPartialDiffs($model, $targetVersion, $snapshotAhead->version);
        }

        // Build a small array of potential approaches
        $candidates = [];

        // Direct approach
        $candidates[] = new ApproachPlan(
            method: ApproachMethod::Direct,
            cost: $directCost,
        );

        // Forward from snapshotBehind
        if ($snapshotBehindCost !== null) {
            $candidates[] = new ApproachPlan(
                method: ApproachMethod::From_Snapshot,
                cost: $snapshotBehindCost,
                snapshot: $snapshotBehind,
            );
        }

        // Backward from snapshotAhead
        if ($snapshotAheadCost !== null) {
            $candidates[] = new ApproachPlan(
                method: ApproachMethod::From_Snapshot,
                cost: $snapshotAheadCost,
                snapshot: $snapshotAhead,
            );
        }

        // Pick the lowest cost
        return collect($candidates)->sortBy('cost')->first();
    }

    /**
     * Count how many partial diffs lie strictly between $fromVersion and $toVersion (inclusive of $toVersion).
     */
    protected function countPartialDiffs(Model $model, int $fromVersion, int $toVersion): int
    {
        if ($toVersion <= $fromVersion) {
            return 0;
        }

        return $model->versions
            ->where('version', '>', $fromVersion)
            ->where('version', '<=', $toVersion)
            ->count();
    }

    /**
     * Find the closest snapshot at or below $version.
     */
    protected function findNearestSnapshotBehind(Model $model, int $version)
    {
        return $model->versions
            ->where('is_snapshot', true)
            ->where('version', '<=', $version)
            ->sortByDesc('version')
            ->first();
    }

    /**
     * Find the closest snapshot at or above $version.
     */
    protected function findNearestSnapshotAhead(Model $model, int $version)
    {
        return $model->versions
            ->where('is_snapshot', true)
            ->where('version', '>=', $version)
            ->sortBy('version')
            ->first();
    }
}
