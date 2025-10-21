<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids, Rewindable;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'description',
        'price',
    ];
}
