<?php

namespace AvocetShores\LaravelRewind\Tests\Models;

use AvocetShores\LaravelRewind\Models\RewindVersion;

class CustomRewindVersion extends RewindVersion
{
    public function getIsCustomAttribute(): bool
    {
        return true;
    }
}
