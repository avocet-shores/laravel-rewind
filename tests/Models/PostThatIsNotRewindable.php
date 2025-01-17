<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use Illuminate\Database\Eloquent\Model;

class PostThatIsNotRewindable extends Model
{
    protected $table = 'posts';

    protected $fillable = [
        'user_id',
        'title',
        'body',
    ];
}
