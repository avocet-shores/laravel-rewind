<?php

namespace AvocetShores\LaravelRewind\Builders;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Exceptions\AsOfBuilderUsageException;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\StateBuilder;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Point-in-time query builder.
 *
 * Reconstructs all instances of a Rewindable model as they existed at a past
 * timestamp, honouring the package's diff + snapshot engine.
 *
 * Returned models are read-only: calling save/update/delete raises
 * ReconstructedModelIsReadOnlyException. Use $model->replicate()->save() to
 * persist a historical copy as a new row.
 *
 * Caveats:
 *  - Relations (e.g. $post->user) still hit the live tables. Reconstruct
 *    related models separately via their own asOf() call.
 *  - The base query passed into scopeAsOf must be empty. Apply filters via
 *    where()/whereIn()/whereBetween()/etc. on AsOfBuilder itself, not on the
 *    Eloquent builder beforehand.
 *  - orderBy is applied in-memory after reconstruction; it is incompatible
 *    with chunk() and cursor().
 *
 * @see README.md "Point-in-Time Queries"
 */
class AsOfBuilder
{
    /** @var list<array<string, mixed>> */
    protected array $wheres = [];

    /** @var list<array{column: string, direction: string}> */
    protected array $orderBys = [];

    protected ?int $limitValue = null;

    /** @var array<int|string, array>|null */
    protected ?array $resolvedCache = null;

    protected const ALLOWED_BASIC_OPERATORS = [
        '=', '==', '===', '!=', '<>', '<', '>', '<=', '>=', 'like', 'LIKE',
    ];

    public function __construct(
        protected Builder $baseBuilder,
        protected Carbon $timestamp,
    ) {
        $this->guardBaseBuilderIsEmpty();
    }

    /**
     * Ensure the incoming Eloquent builder has no prior constraints.
     *
     * Pre-asOf wheres/joins/orders would apply live-table semantics to values
     * that differ in the reconstructed state, silently producing wrong results.
     * Force the user to chain filters after asOf() instead.
     */
    protected function guardBaseBuilderIsEmpty(): void
    {
        $query = $this->baseBuilder->getQuery();
        $offenders = [];

        if (! empty($query->wheres)) {
            $offenders[] = 'wheres';
        }
        if (! empty($query->joins)) {
            $offenders[] = 'joins';
        }
        if (! empty($query->groups)) {
            $offenders[] = 'groups';
        }
        if (! empty($query->havings)) {
            $offenders[] = 'havings';
        }
        if (! empty($query->unions)) {
            $offenders[] = 'unions';
        }
        if (! empty($query->orders)) {
            $offenders[] = 'orders';
        }
        if ($query->limit !== null) {
            $offenders[] = 'limit';
        }
        if ($query->offset !== null) {
            $offenders[] = 'offset';
        }

        if (empty($offenders)) {
            return;
        }

        throw new AsOfBuilderUsageException(sprintf(
            'asOf() must be called on an empty query. The base query has %s. Apply filters after asOf(): %s::asOf($timestamp)->where(...)->get(). Pre-asOf filters would mix live-table semantics with reconstructed attributes.',
            implode(', ', $offenders),
            class_basename($this->baseBuilder->getModel()),
        ));
    }

    /* ------------------------------------------------------------------
     | Fluent filter methods
     | ------------------------------------------------------------------ */

    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! in_array($operator, self::ALLOWED_BASIC_OPERATORS, true)) {
            throw new AsOfBuilderUsageException(sprintf(
                'Operator "%s" is not supported by AsOfBuilder::where(). Supported: %s. Use whereIn/whereNull/whereBetween for other comparisons.',
                is_scalar($operator) ? (string) $operator : gettype($operator),
                implode(', ', self::ALLOWED_BASIC_OPERATORS),
            ));
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
        $this->invalidateCache();

        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'value' => array_values($values)];
        $this->invalidateCache();

        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['type' => 'notIn', 'column' => $column, 'value' => array_values($values)];
        $this->invalidateCache();

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column];
        $this->invalidateCache();

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'notNull', 'column' => $column];
        $this->invalidateCache();

        return $this;
    }

    public function whereBetween(string $column, array $range): static
    {
        if (count($range) !== 2) {
            throw new AsOfBuilderUsageException('whereBetween requires an array of exactly two values: [min, max].');
        }

        $this->wheres[] = ['type' => 'between', 'column' => $column, 'value' => array_values($range)];
        $this->invalidateCache();

        return $this;
    }

    public function whereNotBetween(string $column, array $range): static
    {
        if (count($range) !== 2) {
            throw new AsOfBuilderUsageException('whereNotBetween requires an array of exactly two values: [min, max].');
        }

        $this->wheres[] = ['type' => 'notBetween', 'column' => $column, 'value' => array_values($range)];
        $this->invalidateCache();

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orderBys[] = ['column' => $column, 'direction' => $direction];
        // orderBy affects output order, not the reconstruction set, so the
        // reconstruction cache remains valid.

        return $this;
    }

    public function limit(int $value): static
    {
        $this->limitValue = $value;

        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /* ------------------------------------------------------------------
     | Terminal methods
     | ------------------------------------------------------------------ */

    /**
     * Execute the query and return reconstructed models.
     */
    public function get(): Collection
    {
        return $this->applyConstraintsAndHydrate($this->resolveReconstructions());
    }

    /**
     * Return the first reconstructed model matching the constraints, or null.
     *
     * Short-circuits iteration so only the first passing model is hydrated
     * (or all of them, if no where filter matches). Respects orderBy by
     * materialising and sorting via get() when ordering is requested.
     */
    public function first(): ?Model
    {
        if (! empty($this->orderBys)) {
            // With ordering, we can't short-circuit: need the full sorted set.
            $originalLimit = $this->limitValue;
            $this->limitValue = 1;
            $result = $this->get()->first();
            $this->limitValue = $originalLimit;

            return $result;
        }

        foreach ($this->resolveReconstructions() as $modelId => $attributes) {
            $model = $this->hydrateReconstruction($modelId, $attributes);
            if ($this->passesAllWheres($model)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Count reconstructed models matching the constraints. Ignores limit.
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->resolveReconstructions() as $modelId => $attributes) {
            $model = $this->hydrateReconstruction($modelId, $attributes);
            if ($this->passesAllWheres($model)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Iterate the result set in fixed-size slices, hydrating only one slice
     * at a time. Incompatible with orderBy (ordering is post-reconstruction).
     *
     * The callback receives an Eloquent Collection and may return false to
     * halt iteration, matching Eloquent's chunk() semantics.
     */
    public function chunk(int $size, Closure $callback): bool
    {
        if ($size < 1) {
            throw new AsOfBuilderUsageException('chunk size must be >= 1.');
        }

        if (! empty($this->orderBys)) {
            throw new AsOfBuilderUsageException(
                'orderBy cannot be combined with chunk() because ordering happens after reconstruction. Materialise via get() if sorting is required, or sort inside the callback.'
            );
        }

        $remaining = $this->limitValue;
        $slice = new Collection;

        foreach ($this->resolveReconstructions() as $modelId => $attributes) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $model = $this->hydrateReconstruction($modelId, $attributes);
            if (! $this->passesAllWheres($model)) {
                continue;
            }

            $slice->push($model);

            if ($remaining !== null) {
                $remaining--;
            }

            if ($slice->count() === $size) {
                if ($callback($slice) === false) {
                    return false;
                }
                $slice = new Collection;
            }
        }

        if ($slice->isNotEmpty()) {
            if ($callback($slice) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Yield reconstructed models one at a time. Incompatible with orderBy.
     */
    public function cursor(): Generator
    {
        if (! empty($this->orderBys)) {
            throw new AsOfBuilderUsageException(
                'orderBy cannot be combined with cursor() because ordering happens after reconstruction. Use get() if sorting is required.'
            );
        }

        $yielded = 0;

        foreach ($this->resolveReconstructions() as $modelId => $attributes) {
            $model = $this->hydrateReconstruction($modelId, $attributes);
            if (! $this->passesAllWheres($model)) {
                continue;
            }

            yield $model;

            $yielded++;
            if ($this->limitValue !== null && $yielded >= $this->limitValue) {
                return;
            }
        }
    }

    /* ------------------------------------------------------------------
     | Internal pipeline
     | ------------------------------------------------------------------ */

    /**
     * Resolve the active [model_id => attributes] map at the timestamp.
     * Memoised; invalidated when any filter mutator is called.
     *
     * @return array<int|string, array<string, mixed>>
     */
    protected function resolveReconstructions(): array
    {
        if ($this->resolvedCache !== null) {
            return $this->resolvedCache;
        }

        $modelInstance = $this->baseBuilder->getModel();
        $modelType = $modelInstance->getMorphClass();
        $versionModelClass = RewindVersion::resolveVersionModelClass();

        // TODO: steps 1 and 2 could be consolidated into a single query
        // via JOIN on a grouped subquery if profiling warrants it.

        // 1. Target version per model_id = MAX(version) where created_at <= timestamp.
        //    Cast target to int; model_id keys retain the driver's native type.
        $targetVersions = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->where('created_at', '<=', $this->timestamp)
            ->selectRaw('model_id, MAX(version) as target_version')
            ->groupBy('model_id')
            ->pluck('target_version', 'model_id')
            ->map(fn ($version) => (int) $version);

        if ($targetVersions->isEmpty()) {
            return $this->resolvedCache = [];
        }

        // 2. Identify models whose target version is a deletion marker.
        $deletedModelIds = $versionModelClass::query()
            ->where('model_type', $modelType)
            ->whereIn('model_id', $targetVersions->keys())
            ->where('event_type', VersionEventType::Deleted)
            ->get()
            ->filter(function ($record) use ($targetVersions) {
                return $targetVersions->has($record->model_id)
                    && $record->version == $targetVersions->get($record->model_id);
            })
            ->pluck('model_id')
            ->all();

        $activeModelVersions = $targetVersions->reject(function ($version, $modelId) use ($deletedModelIds) {
            return in_array($modelId, $deletedModelIds);
        });

        if ($activeModelVersions->isEmpty()) {
            return $this->resolvedCache = [];
        }

        // 3. Batch reconstruct via the shared snapshot+diff engine.
        $stateBuilder = app(StateBuilder::class);
        $this->resolvedCache = $stateBuilder->reconstructMultipleModelsAtVersions(
            $activeModelVersions->all(),
            $modelType,
        );

        return $this->resolvedCache;
    }

    /**
     * Hydrate + filter + order + limit the reconstructions into a Collection.
     */
    protected function applyConstraintsAndHydrate(array $reconstructions): Collection
    {
        $models = new Collection;

        foreach ($reconstructions as $modelId => $attributes) {
            $model = $this->hydrateReconstruction($modelId, $attributes);
            if ($this->passesAllWheres($model)) {
                $models->push($model);
            }
        }

        foreach (array_reverse($this->orderBys) as $orderBy) {
            $models = $models->sortBy(
                $orderBy['column'],
                SORT_REGULAR,
                strtolower($orderBy['direction']) === 'desc',
            )->values();
        }

        if ($this->limitValue !== null) {
            $models = $models->take($this->limitValue);
        }

        return $models;
    }

    protected function hydrateReconstruction(int|string $modelId, array $attributes): Model
    {
        $modelInstance = $this->baseBuilder->getModel();
        $keyName = $modelInstance->getKeyName();
        $castKeyToInt = $modelInstance->getKeyType() === 'int';

        $model = $modelInstance->newInstance([], true);
        $model->setRawAttributes(array_merge(
            [$keyName => $castKeyToInt ? (int) $modelId : $modelId],
            $attributes,
        ), true);

        if (method_exists($model, 'markAsRewindReconstruction')) {
            $model->markAsRewindReconstruction();
        }

        return $model;
    }

    protected function passesAllWheres(Model $model): bool
    {
        foreach ($this->wheres as $where) {
            if (! $this->evaluateWhere($model, $where)) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateWhere(Model $model, array $where): bool
    {
        $actual = $model->getAttribute($where['column']);

        return match ($where['type']) {
            'basic' => $this->evaluateBasic($actual, $where['operator'], $where['value']),
            'in' => $this->evaluateIn($actual, $where['value']),
            'notIn' => ! $this->evaluateIn($actual, $where['value']),
            'null' => $actual === null,
            'notNull' => $actual !== null,
            'between' => $this->evaluateBetween($actual, $where['value']),
            'notBetween' => ! $this->evaluateBetween($actual, $where['value']),
        };
    }

    protected function evaluateBasic(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=', '<>' => $actual != $expected,
            '<' => $actual < $expected,
            '>' => $actual > $expected,
            '<=' => $actual <= $expected,
            '>=' => $actual >= $expected,
            'like', 'LIKE' => $this->evaluateLike($actual, (string) $expected),
        };
    }

    protected function evaluateIn(mixed $actual, array $values): bool
    {
        foreach ($values as $candidate) {
            if ($actual == $candidate) {
                return true;
            }
        }

        return false;
    }

    protected function evaluateBetween(mixed $actual, array $range): bool
    {
        [$min, $max] = $range;

        return $actual >= $min && $actual <= $max;
    }

    protected function evaluateLike(mixed $actual, string $pattern): bool
    {
        // Split on SQL wildcards so preg_quote only escapes literal segments.
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

    protected function invalidateCache(): void
    {
        $this->resolvedCache = null;
    }
}
