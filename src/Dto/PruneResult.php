<?php

namespace AvocetShores\LaravelRewind\Dto;

class PruneResult
{
    public function __construct(
        public int $totalDeleted,
        public array $deletedPerModelType = [],
    ) {}
}
