<?php

namespace AvocetShores\LaravelRewind\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array $old_values
 * @property array $new_values
 * @property int $version
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
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'version' => 'integer',
        'is_snapshot' => 'boolean',
    ];

    /**
     * Dynamically set the table name from config in the constructor.
     */
    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('rewind.database_connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('rewind.table_name'));
        }

        $this->fillable[] = config('rewind.user_id_column');
        $this->casts[config('rewind.user_id_column')] = 'integer';

        parent::__construct($attributes);
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
}
