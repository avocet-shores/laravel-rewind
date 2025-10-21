<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasUlids, Rewindable;

    protected $table = 'articles';

    protected $fillable = [
        'title',
        'content',
        'author',
    ];
}
