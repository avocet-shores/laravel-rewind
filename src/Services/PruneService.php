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
     */
    protected function getVersionsToDeleteForModel(
        Collection $versions,
        ?int $retentionDays,
        ?int $retentionCount
    ): Collection {
        $candidates = collect();

        // Sort versions by version number
        $sortedVersions = $versions->sortBy('version');

        // Apply age-based retention
        if ($retentionDays !== null) {
            $cutoffDate = now()->subDays($retentionDays);
            $candidates = $candidates->merge(
                $sortedVersions->filter(fn ($v) => $v->created_at->lt($cutoffDate))
            );
        }

        // Apply count-based retention
        if ($retentionCount !== null && $sortedVersions->count() > $retentionCount) {
            // Get versions beyond the retention count (oldest ones)
            $versionsToKeepByCount = $sortedVersions->sortByDesc('version')->take($retentionCount);
            $versionNumbersToKeep = $versionsToKeepByCount->pluck('version')->toArray();

            $candidates = $candidates->merge(
                $sortedVersions->filter(fn ($v) => ! in_array($v->version, $versionNumbersToKeep))
            );
        }

        // Remove duplicates (a version might match both age and count criteria)
        $candidates = $candidates->unique('id');

        // Apply safety filters to preserve critical versions
        return $this->preserveCriticalVersions($candidates, $sortedVersions);
    }

    /**
     * Filter out versions that should never be deleted for data integrity.
     */
    protected function preserveCriticalVersions(Collection $candidates, Collection $allVersionsForModel): Collection
    {
        $keepVersionOne = config('rewind.pruning.keep_version_one', true);
        $keepSnapshots = config('rewind.pruning.keep_snapshots', true);
        $snapshotInterval = config('rewind.snapshot_interval', 10);

        return $candidates->filter(function ($version) use ($keepVersionOne, $keepSnapshots, $snapshotInterval, $allVersionsForModel) {
            // Never delete version 1 if configured
            if ($keepVersionOne && $version->version === 1) {
                return false;
            }

            // Preserve snapshots if configured
            if ($keepSnapshots && $version->is_snapshot) {
                // Keep snapshots that fall on the snapshot interval boundary
                if ($version->version % $snapshotInterval === 0) {
                    return false;
                }

                // Keep the last snapshot before the newest version
                $maxVersion = $allVersionsForModel->max('version');
                $lastSnapshotBeforeMax = $allVersionsForModel
                    ->where('is_snapshot', true)
                    ->where('version', '<', $maxVersion)
                    ->sortByDesc('version')
                    ->first();

                if ($lastSnapshotBeforeMax && $lastSnapshotBeforeMax->id === $version->id) {
                    return false;
                }
            }

            return true;
        });
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
