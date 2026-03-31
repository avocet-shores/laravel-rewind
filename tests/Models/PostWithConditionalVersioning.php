<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Database\Eloquent\Model;

class PostWithConditionalVersioning extends Model
{
    use Rewindable;

    protected $table = 'posts';

    protected $fillable = [
        'user_id',
        'title',
        'body',
    ];

    public static function shouldVersion(array $changedAttributes): bool
    {
        return array_key_exists('title', $changedAttributes);
    }
}
