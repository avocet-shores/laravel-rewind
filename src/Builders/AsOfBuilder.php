<?php

namespace AvocetShores\LaravelRewind\Builders;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\StateBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AsOfBuilder
{
    protected array $wheres = [];

    protected array $orderBys = [];

    protected ?int $limitValue = null;

    public function __construct(
        protected Builder $baseBuilder,
        protected Carbon $timestamp,
    ) {}

    /**
     * Add a where constraint to filter reconstructed models.
     *
     * Supports: where('col', 'val'), where('col', 'op', 'val')
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = compact('column', 'operator', 'value');

        return $this;
    }

    /**
     * Add an orderBy constraint to sort reconstructed models.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orderBys[] = compact('column', 'direction');

        return $this;
    }

    /**
     * Limit the number of results returned.
     */
    public function limit(int $value): static
    {
        $this->limitValue = $value;

        return $this;
    }

    /**
     * Alias for limit().
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Execute the query and return reconstructed models.
     */
    public function get(): Collection
    {
        $modelInstance = $this->baseBuilder->getModel();
        $modelType = $modelInstance->getMorphClass();
        $versionModelClass = RewindVersion::resolveVersionModelClass();

        // 1. Find the target version for each model_id (max version where created_at <= timestamp)
        $targetVersions = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->where('created_at', '<=', $this->timestamp)
            ->selectRaw('model_id, MAX(version) as target_version')
            ->groupBy('model_id')
            ->pluck('target_version', 'model_id');

        if ($targetVersions->isEmpty()) {
            return new Collection;
        }

        // 2. Load the target version records to check for deleted models.
        //    We only need event_type for each model's target version.
        $deletedModelIds = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->whereIn('model_id', $targetVersions->keys())
            ->where('event_type', VersionEventType::Deleted)
            ->get()
            ->filter(function ($record) use ($targetVersions) {
                return $record->version == $targetVersions[$record->model_id];
            })
            ->pluck('model_id')
            ->all();

        // 3. Exclude models whose target version is a "deleted" event
        $activeModelVersions = $targetVersions->reject(function ($version, $modelId) use ($deletedModelIds) {
            return in_array($modelId, $deletedModelIds);
        });

        if ($activeModelVersions->isEmpty()) {
            return new Collection;
        }

        // 4. Batch reconstruct all models
        $stateBuilder = app(StateBuilder::class);
        $reconstructedStates = $stateBuilder->reconstructMultipleModelsAtVersions(
            $activeModelVersions->all(),
            $modelType,
        );

        // 5. Hydrate model instances
        $models = new Collection;
        foreach ($reconstructedStates as $modelId => $attributes) {
            $model = $modelInstance->newInstance([], true);
            $model->setRawAttributes(array_merge(
                [$modelInstance->getKeyName() => $modelId],
                $attributes,
            ), true);

            $models->push($model);
        }

        // 6. Apply in-memory where constraints
        foreach ($this->wheres as $where) {
            $models = $models->filter(function (Model $model) use ($where) {
                return $this->evaluateWhere($model, $where);
            })->values();
        }

        // 7. Apply ordering
        foreach (array_reverse($this->orderBys) as $orderBy) {
            $models = $models->sortBy(
                $orderBy['column'],
                SORT_REGULAR,
                strtolower($orderBy['direction']) === 'desc',
            )->values();
        }

        // 8. Apply limit
        if ($this->limitValue !== null) {
            $models = $models->take($this->limitValue);
        }

        return $models;
    }

    /**
     * Get the first reconstructed model matching the constraints.
     */
    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Count the reconstructed models matching the constraints.
     */
    public function count(): int
    {
        // Temporarily remove limit for counting
        $originalLimit = $this->limitValue;
        $this->limitValue = null;

        $count = $this->get()->count();

        $this->limitValue = $originalLimit;

        return $count;
    }

    /**
     * Evaluate a single where constraint against a model.
     */
    protected function evaluateWhere(Model $model, array $where): bool
    {
        $actual = $model->getAttribute($where['column']);
        $expected = $where['value'];

        return match ($where['operator']) {
            '=' => $actual == $expected,
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '<>' => $actual != $expected,
            '<' => $actual < $expected,
            '>' => $actual > $expected,
            '<=' => $actual <= $expected,
            '>=' => $actual >= $expected,
            'like', 'LIKE' => $this->evaluateLike($actual, $expected),
            default => false,
        };
    }

    /**
     * Evaluate a LIKE comparison.
     */
    protected function evaluateLike(mixed $actual, string $pattern): bool
    {
        // Split the pattern on SQL wildcards, quote each literal segment,
        // then rejoin with regex equivalents. This avoids preg_quote
        // escaping the wildcard characters themselves.
        $segments = preg_split('/(%|_)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '/^';
        foreach ($segments as $segment) {
            $regex .= match ($segment) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($segment, '/'),
            };
        }
        $regex .= '$/i';

        return (bool) preg_match($regex, (string) $actual);
    }
}
