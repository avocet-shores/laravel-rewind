<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Dto\PruneResult;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VersionPruner
{
    public function __construct(
        protected StateBuilder $stateBuilder,
    ) {}

    /**
     * Prune versions based on criteria. Returns the result with deletion counts.
     */
    public function prune(
        ?int $keepCount = null,
        ?int $olderThanDays = null,
        ?array $modelTypes = null,
        bool $pretend = false,
    ): PruneResult {
        $totalDeleted = 0;
        $deletedPerModelType = [];

        $cutoffDate = $olderThanDays !== null
            ? Carbon::now()->subDays($olderThanDays)
            : null;

        // Query distinct (model_type, model_id) groups
        $versionModelClass = RewindVersion::resolveVersionModelClass();
        $groupsQuery = $versionModelClass::query()
            ->select('model_type', 'model_id')
            ->distinct();

        if ($modelTypes) {
            $groupsQuery->whereIn('model_type', $modelTypes);
        }

        $groupsQuery->orderBy('model_type')->orderBy('model_id')
            ->chunk(100, function ($groups) use ($keepCount, $cutoffDate, $pretend, &$totalDeleted, &$deletedPerModelType) {
                foreach ($groups as $group) {
                    $deleted = $this->pruneGroup(
                        modelType: $group->model_type,
                        modelId: $group->model_id,
                        keepCount: $keepCount,
                        cutoffDate: $cutoffDate,
                        pretend: $pretend,
                    );

                    if ($deleted > 0) {
                        $totalDeleted += $deleted;
                        $deletedPerModelType[$group->model_type] = ($deletedPerModelType[$group->model_type] ?? 0) + $deleted;
                    }
                }
            });

        return new PruneResult($totalDeleted, $deletedPerModelType);
    }

    /**
     * Prune versions for a single model instance, keeping at most $maxVersions.
     */
    public function pruneForModel(Model $model, int $maxVersions): int
    {
        return $this->pruneGroup(
            modelType: $model->getMorphClass(),
            modelId: $model->getKey(),
            keepCount: $maxVersions,
            cutoffDate: null,
            pretend: false,
        );
    }

    /**
     * Prune versions for a single (model_type, model_id) group.
     */
    protected function pruneGroup(
        string $modelType,
        mixed $modelId,
        ?int $keepCount,
        ?Carbon $cutoffDate,
        bool $pretend,
    ): int {
        if ($pretend) {
            $versions = $this->loadVersionMetadata($modelType, $modelId);

            if ($versions->isEmpty()) {
                return 0;
            }

            return $this->determinePrunable($versions, $keepCount, $cutoffDate)->count();
        }

        $connection = RewindVersion::newVersionModel()->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $modelType, $modelId, $keepCount, $cutoffDate
        ) {
            // Load metadata INSIDE the transaction with a pessimistic lock
            $versions = $this->loadVersionMetadata($modelType, $modelId, lockForUpdate: true);

            if ($versions->isEmpty()) {
                return 0;
            }

            $prunable = $this->determinePrunable($versions, $keepCount, $cutoffDate);

            if ($prunable->isEmpty()) {
                return 0;
            }

            $prunableIds = $prunable->pluck('id')->toArray();

            // Identify the new oldest remaining version
            $remainingVersions = $versions->reject(fn ($v) => in_array($v->id, $prunableIds));
            $newOldest = $remainingVersions->sortBy('version')->first();

            // If the new oldest version is not a snapshot, convert it
            if ($newOldest && ! $newOldest->is_snapshot) {
                $this->convertToSnapshot($modelType, $modelId, $newOldest->version);
            }

            // Delete prunable versions
            $versionModelClass = RewindVersion::resolveVersionModelClass();
            $versionModelClass::query()
                ->whereIn('id', $prunableIds)
                ->delete();

            return count($prunableIds);
        });
    }

    /**
     * Load version metadata for a given model instance.
     */
    private function loadVersionMetadata(string $modelType, mixed $modelId, bool $lockForUpdate = false): Collection
    {
        $versionModelClass = RewindVersion::resolveVersionModelClass();
        $query = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->select('id', 'version', 'is_snapshot', 'created_at')
            ->orderBy('version');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Determine which versions are prunable from the given collection.
     */
    private function determinePrunable(Collection $versions, ?int $keepCount, ?Carbon $cutoffDate): Collection
    {
        $maxVersion = $versions->max('version');
        $keepIds = collect();

        // Always keep the latest version
        $latestVersion = $versions->where('version', $maxVersion)->first();
        $keepIds->push($latestVersion->id);

        if ($keepCount !== null) {
            // Keep the last N versions by version number descending
            $keptByCount = $versions->sortByDesc('version')->take($keepCount);
            $keepIds = $keepIds->merge($keptByCount->pluck('id'));
        }

        return $versions->reject(function ($v) use ($keepIds, $cutoffDate) {
            // Never prune versions in the keep set
            if ($keepIds->contains($v->id)) {
                return true;
            }

            // If cutoff date is set, only prune versions older than the cutoff
            if ($cutoffDate !== null) {
                return Carbon::parse($v->created_at)->isAfter($cutoffDate);
            }

            return false;
        });
    }

    /**
     * Reconstruct the full state at a given version and convert it to a snapshot.
     */
    protected function convertToSnapshot(string $modelType, mixed $modelId, int $targetVersion): void
    {
        $state = $this->stateBuilder->reconstructStateAtVersion($modelType, $modelId, $targetVersion);

        $versionModelClass = RewindVersion::resolveVersionModelClass();
        $version = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->where('version', $targetVersion)
            ->firstOrFail();

        $version->new_values = $state;
        $version->is_snapshot = true;
        $version->save();
    }
}
