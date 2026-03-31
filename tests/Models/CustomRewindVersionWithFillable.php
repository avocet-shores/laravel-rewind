<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Models\RewindVersion;

class CustomRewindVersionWithFillable extends RewindVersion
{
    protected $fillable = [
        'custom_column',
    ];

    public function getIsCustomAttribute(): bool
    {
        return true;
    }
}
