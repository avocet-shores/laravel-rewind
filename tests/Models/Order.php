<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use Rewindable;

    protected $table = 'orders';

    protected $fillable = [
        'user_id',
        'status',
        'payment_status',
        'total',
    ];

    protected array $rewindStateFields = ['status', 'payment_status'];
}
