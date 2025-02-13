<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use Rewindable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'content',
    ];
}
