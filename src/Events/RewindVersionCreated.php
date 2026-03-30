<?php

namespace AvocetShores\LaravelRewind\Events;

use AvocetShores\LaravelRewind\Models\RewindVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class RewindVersionCreated
{
    use Dispatchable;

    public function __construct(
        public Model $model,
        public RewindVersion $version,
    ) {}
}
