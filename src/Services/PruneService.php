<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Dto\PruneResult;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PruneService
{
    /**
     * Prune old rewind versions based on retention policies.
     *
     * @param  array  $options  Options for pruning
     *                          - 'days' (int|null): Keep versions created within the last X days
     *                          - 'keep' (int|null): Keep the last X versions per model
     *                          - 'model' (string|null): Only prune versions for a specific model type
     *                          - 'dry_run' (bool): Preview without actually deleting
     */
    public function prune(array $options = []): PruneResult
    {
        $retentionDays = $options['days'] ?? config('rewind.pruning.retention_days');
        $retentionCount = $options['keep'] ?? config('rewind.pruning.retention_count');
        $modelType = $options['model'] ?? null;
        $isDryRun = $options['dry_run'] ?? false;

        // If no retention policy is set, don't delete anything
        if ($retentionDays === null && $retentionCount === null) {
            return new PruneResult(
                totalExamined: 0,
                totalDeleted: 0,
                totalPreserved: 0,
                deletedByModel: [],
                preservedByModel: [],
                isDryRun: $isDryRun
            );
        }

        $versionsToDelete = $this->getVersionsToDelete($retentionDays, $retentionCount, $modelType);
        $totalExamined = $this->getTotalVersionCount($modelType);

        // Collect statistics by model type
        $deletedByModel = $versionsToDelete->groupBy('model_type')
            ->map(fn ($group) => $group->count())
            ->toArray();

        $totalDeleted = $versionsToDelete->count();
        $totalPreserved = $totalExamined - $totalDeleted;

        // Calculate preserved by model
        $preservedByModel = [];
        if ($modelType) {
            $preservedByModel = [$modelType => $totalPreserved];
        } else {
            $allByModel = RewindVersion::select('model_type', DB::raw('count(*) as count'))
                ->groupBy('model_type')
                ->pluck('count', 'model_type')
                ->toArray();

            foreach ($allByModel as $type => $count) {
                $deleted = $deletedByModel[$type] ?? 0;
                $preservedByModel[$type] = $count - $deleted;
            }
        }

        // Perform actual deletion (unless dry run)
        if (! $isDryRun && $totalDeleted > 0) {
            $this->deleteVersions($versionsToDelete);
        }

        return new PruneResult(
            totalExamined: $totalExamined,
            totalDeleted: $totalDeleted,
            totalPreserved: $totalPreserved,
            deletedByModel: $deletedByModel,
            preservedByModel: $preservedByModel,
            isDryRun: $isDryRun
        );
    }

    /**
     * Get all versions that should be deleted based on retention policies.
     */
    protected function getVersionsToDelete(?int $retentionDays, ?int $retentionCount, ?string $modelType): Collection
    {
        $query = RewindVersion::query();

        // Filter by model type if specified
        if ($modelType) {
            $query->where('model_type', $modelType);
        }

        $allVersions = $query->get();

        // Group versions by model instance (model_type + model_id)
        $versionsByModel = $allVersions->groupBy(function ($version) {
            return $version->model_type.':'.$version->model_id;
        });

        $versionsToDelete = collect();

        foreach ($versionsByModel as $modelKey => $versions) {
            $deletable = $this->getVersionsToDeleteForModel(
                $versions,
                $retentionDays,
                $retentionCount
            );

            $versionsToDelete = $versionsToDelete->merge($deletable);
        }

        return $versionsToDelete;
    }

    /**
     * Determine which versions to delete for a specific model instance.
     *
     * When both policies are set, only delete versions that violate BOTH policies
     * (intersection), making it the most permissive approach (keeps more).
     */
    protected function getVersionsToDeleteForModel(
        Collection $versions,
        ?int $retentionDays,
        ?int $retentionCount
    ): Collection {
        // Sort versions by version number
        $sortedVersions = $versions->sortBy('version');

        $candidates = collect();

        // If both policies are set, only delete if BOTH agree (intersection = most permissive)
        if ($retentionDays !== null && $retentionCount !== null) {
            // Get versions that violate age policy
            $cutoffDate = now()->subDays($retentionDays);
            $oldVersions = $sortedVersions->filter(fn ($v) => $v->created_at->lt($cutoffDate));

            // Get versions that violate count policy
            if ($sortedVersions->count() > $retentionCount) {
                $versionsToKeepByCount = $sortedVersions->sortByDesc('version')->take($retentionCount);
                $versionNumbersToKeep = $versionsToKeepByCount->pluck('version')->toArray();
                $beyondCount = $sortedVersions->filter(fn ($v) => ! in_array($v->version, $versionNumbersToKeep));

                // Only delete versions that violate BOTH policies (intersection)
                $oldVersionIds = $oldVersions->pluck('id')->toArray();
                $beyondCountIds = $beyondCount->pluck('id')->toArray();
                $intersectionIds = array_intersect($oldVersionIds, $beyondCountIds);
                $candidates = $sortedVersions->whereIn('id', $intersectionIds);
            }
            // If count policy doesn't trigger, no candidates for deletion
        } elseif ($retentionDays !== null) {
            // Only age policy: delete old versions
            $cutoffDate = now()->subDays($retentionDays);
            $candidates = $sortedVersions->filter(fn ($v) => $v->created_at->lt($cutoffDate));
        } elseif ($retentionCount !== null) {
            // Only count policy: delete versions beyond count
            if ($sortedVersions->count() > $retentionCount) {
                $versionsToKeepByCount = $sortedVersions->sortByDesc('version')->take($retentionCount);
                $versionNumbersToKeep = $versionsToKeepByCount->pluck('version')->toArray();
                $candidates = $sortedVersions->filter(fn ($v) => ! in_array($v->version, $versionNumbersToKeep));
            }
        }

        // Apply safety filters to preserve critical versions
        return $this->preserveCriticalVersions($candidates, $sortedVersions);
    }

    /**
     * Filter out versions that should never be deleted for data integrity.
     *
     * When keep_snapshots is true, this maintains the snapshot system's ability
     * to reconstruct history efficiently by keeping all versions from the oldest
     * relevant snapshot forward.
     *
     * Returns only the versions that are safe to delete (filters out critical ones).
     */
    protected function preserveCriticalVersions(Collection $candidates, Collection $allVersionsForModel): Collection
    {
        $keepVersionOne = config('rewind.pruning.keep_version_one', true);
        $keepSnapshots = config('rewind.pruning.keep_snapshots', true);

        // Start by removing version 1 if configured to keep it
        if ($keepVersionOne) {
            $candidates = $candidates->reject(fn ($v) => $v->version === 1);
        }

        // If not keeping snapshots, we're done
        if (! $keepSnapshots) {
            return $candidates;
        }

        // Find the oldest version we're keeping (not in deletion candidates)
        $candidateIds = $candidates->pluck('id')->toArray();
        $versionsToKeep = $allVersionsForModel->reject(fn ($v) => in_array($v->id, $candidateIds));

        if ($versionsToKeep->isEmpty()) {
            // We're deleting everything, no snapshot preservation needed
            return $candidates;
        }

        $oldestKeptVersion = $versionsToKeep->sortBy('version')->first();

        // Find the nearest snapshot at or before the oldest kept version
        $anchorSnapshot = $allVersionsForModel
            ->where('is_snapshot', true)
            ->where('version', '<=', $oldestKeptVersion->version)
            ->sortByDesc('version')
            ->first();

        // If no snapshot found before the oldest kept version, check if version 1 exists
        // (version 1 is always a snapshot and serves as the ultimate anchor)
        if (! $anchorSnapshot) {
            $anchorSnapshot = $allVersionsForModel->where('version', 1)->first();
        }

        // If we still have no anchor snapshot, we can't maintain reconstruction capability
        // In this case, just return candidates as-is (rare edge case)
        if (! $anchorSnapshot) {
            return $candidates;
        }

        // Remove all versions >= anchor snapshot from deletion candidates
        // This ensures we keep the snapshot and all diffs needed for reconstruction
        return $candidates->filter(fn ($v) => $v->version < $anchorSnapshot->version);
    }

    /**
     * Delete versions in chunks to avoid memory issues.
     */
    protected function deleteVersions(Collection $versions): void
    {
        $chunkSize = config('rewind.pruning.chunk_size', 1000);
        $versionIds = $versions->pluck('id');

        $versionIds->chunk($chunkSize)->each(function ($chunk) {
            RewindVersion::whereIn('id', $chunk->toArray())->delete();
        });
    }

    /**
     * Get total count of versions (optionally filtered by model type).
     */
    protected function getTotalVersionCount(?string $modelType = null): int
    {
        $query = RewindVersion::query();

        if ($modelType) {
            $query->where('model_type', $modelType);
        }

        return $query->count();
    }
}
