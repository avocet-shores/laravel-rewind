<?php

namespace AvocetShores\LaravelRewind\Events;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class RewindVersionLockTimeout
{
    use Dispatchable;

    public function __construct(
        public Model $model,
        public LockTimeoutException $exception,
        public array $changes,
    ) {}
}
