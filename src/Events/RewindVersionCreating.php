<?php

namespace AvocetShores\LaravelRewind\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewindVersionCreating
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $model,
    ) {}
}
