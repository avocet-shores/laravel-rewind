<?php

namespace AvocetShores\LaravelRewind\Events;

use AvocetShores\LaravelRewind\Enums\VersionEventType;
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
     * @param  VersionEventType|null  $eventType  The type of event that triggered this version (survives serialization for queued listeners).
     * @param  array  $meta  Arbitrary metadata to attach to the version record.
     */
    public function __construct(
        public Model $model,
        public array $changes = [],
        public bool $wasRecentlyCreated = false,
        public ?VersionEventType $eventType = null,
        public array $meta = [],
        public ?string $batchUuid = null,
        public bool $versionDrifted = false,
    ) {}
}
