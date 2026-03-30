<?php

namespace AvocetShores\LaravelRewind\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewindVersionCreating
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Model  $model  The model being versioned.
     * @param  array  $changes  The changed attributes captured at dispatch time (survives serialization for queued listeners).
     * @param  bool  $wasRecentlyCreated  Whether the model was just created (survives serialization for queued listeners).
     */
    public function __construct(
        public Model $model,
        public array $changes = [],
        public bool $wasRecentlyCreated = false,
    ) {}
}
