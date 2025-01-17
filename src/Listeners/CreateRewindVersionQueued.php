<?php

namespace AvocetShores\LaravelRewind\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class CreateRewindVersionQueued extends CreateRewindVersion implements ShouldQueue {}
