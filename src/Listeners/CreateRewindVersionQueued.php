<?php

namespace AvocetShores\LaravelRewind\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class CreateRewindVersionQueued extends CreateRewindVersion implements ShouldQueue
{
    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public int $timeout;

    public function __construct()
    {
        parent::__construct();

        $this->tries = config('rewind.queue.tries', 3);
        $this->backoff = config('rewind.queue.backoff', [2, 10, 30]);
        $this->timeout = config('rewind.queue.timeout', 60);
    }
}
