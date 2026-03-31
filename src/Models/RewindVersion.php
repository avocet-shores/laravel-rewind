<?php

namespace AvocetShores\LaravelRewind\Models;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $model_type
 * @property int $model_id
 * @property array $old_values
 * @property array $new_values
 * @property int $version
 * @property bool $is_snapshot
 * @property VersionEventType|null $event_type
 * @property array|null $meta
 * @property array|null $state_transitions
 * @property string|null $batch_uuid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RewindVersion extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'version',
        'is_snapshot',
        'event_type',
        'meta',
        'state_transitions',
        'batch_uuid',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'version' => 'integer',
        'is_snapshot' => 'boolean',
        'meta' => 'array',
        'state_transitions' => 'array',
        'event_type' => VersionEventType::class,
        'batch_uuid' => 'string',
    ];

    /**
     * Dynamically set the table name from config in the constructor.
     */
    public function __construct(array $attributes = [])
    {
        // Merge required columns so child classes can't accidentally drop them
        $this->fillable = array_unique(array_merge($this->fillable, [
            'model_type', 'model_id', 'old_values', 'new_values',
            'version', 'is_snapshot', 'event_type', 'meta', 'state_transitions', 'batch_uuid',
        ]));

        if (! isset($this->connection)) {
            $this->setConnection(config('rewind.database_connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('rewind.table_name'));
        }

        $userIdColumn = config('rewind.user_id_column');
        if ($userIdColumn !== null) {
            $this->fillable[] = $userIdColumn;
            $this->casts[$userIdColumn] = config('rewind.user_id_cast', 'integer');
        }

        parent::__construct($attributes);
    }

    /**
     * Resolve the configured version model class.
     * Falls back to RewindVersion if not configured.
     *
     * @throws \InvalidArgumentException If the configured class does not extend RewindVersion
     */
    public static function resolveVersionModelClass(): string
    {
        $model = config('rewind.version_model');

        if ($model === null) {
            return static::class;
        }

        if (! is_subclass_of($model, self::class)) {
            throw new \InvalidArgumentException(
                "The configured version model [{$model}] must extend ".self::class
            );
        }

        return $model;
    }

    /**
     * Create a new instance of the configured version model.
     */
    public static function newVersionModel(array $attributes = []): static
    {
        /** @var class-string<static> $class */
        $class = static::resolveVersionModelClass();

        return new $class($attributes);
    }

    /**
     * Scope to versions belonging to a specific model instance.
     */
    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey());
    }

    /**
     * Scope to versions created by a specific user.
     */
    public function scopeByUser(Builder $query, int|string $userId): Builder
    {
        return $query->where(config('rewind.user_id_column'), $userId);
    }

    /**
     * Scope to versions of a specific event type.
     */
    public function scopeOfType(Builder $query, VersionEventType $type): Builder
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope to versions created between two dates.
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope to versions within a version number range (inclusive).
     */
    public function scopeBetweenVersions(Builder $query, int $from, int $to): Builder
    {
        return $query->whereBetween('version', [$from, $to]);
    }

    /**
     * Scope to versions belonging to a specific batch.
     */
    public function scopeInBatch(Builder $query, string $batchUuid): Builder
    {
        return $query->where('batch_uuid', $batchUuid);
    }

    /**
     * Scope to versions where a specific state transition occurred.
     * Pass null for $from or $to to match any value (wildcard).
     */
    public function scopeWhereStateTransition(Builder $query, string $field, mixed $from = null, mixed $to = null): Builder
    {
        $query->whereNotNull("state_transitions->{$field}");

        if ($from !== null) {
            $query->where("state_transitions->{$field}->from", $from);
        }

        if ($to !== null) {
            $query->where("state_transitions->{$field}->to", $to);
        }

        return $query;
    }

    /**
     * Scope to versions where a field transitioned FROM a specific state.
     */
    public function scopeWhereStateWas(Builder $query, string $field, mixed $state): Builder
    {
        return $query->where("state_transitions->{$field}->from", $state);
    }

    /**
     * Scope to versions where a field transitioned TO a specific state.
     */
    public function scopeWhereStateBecame(Builder $query, string $field, mixed $state): Builder
    {
        return $query->where("state_transitions->{$field}->to", $state);
    }

    /**
     * Scope to versions where a specific field had any state transition.
     */
    public function scopeWhereStateChanged(Builder $query, string $field): Builder
    {
        return $query->whereNotNull("state_transitions->{$field}");
    }

    /**
     * Optional relationship to the user who made the change (if user tracking is enabled).
     */
    public function user(): BelongsTo
    {
        // Update this to reference your actual User model namespace if needed.
        return $this->belongsTo(
            config('rewind.user_model'),
            config('rewind.user_id_column')
        );
    }

    /**
     * Get the model that this version belongs to.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
