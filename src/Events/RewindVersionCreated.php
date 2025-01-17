<?php

namespace AvocetShores\LaravelRewind\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RewindVersionCreated
{
    use Dispatchable;

    public function __construct(
        public $model,
        public $version,
    ) {}
}
