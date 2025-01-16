<?php

namespace AvocetShores\LaravelRewind\Dto;

use AvocetShores\LaravelRewind\Enums\ApproachMethod;
use AvocetShores\LaravelRewind\Models\RewindVersion;

class ApproachPlan
{
    public ApproachMethod $method;

    public ?RewindVersion $snapshot;

    public int $cost;

    public function __construct(ApproachMethod $method, int $cost, RewindVersion $snapshot = null)
    {
        $this->method = $method;
        $this->cost = $cost;
        $this->snapshot = $snapshot;
    }
}
