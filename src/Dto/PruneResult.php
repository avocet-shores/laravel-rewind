<?php

namespace AvocetShores\LaravelRewind\Dto;

class PruneResult
{
    public int $totalExamined;

    public int $totalDeleted;

    public int $totalPreserved;

    public array $deletedByModel;

    public array $preservedByModel;

    public bool $isDryRun;

    public function __construct(
        int $totalExamined,
        int $totalDeleted,
        int $totalPreserved,
        array $deletedByModel,
        array $preservedByModel,
        bool $isDryRun = false
    ) {
        $this->totalExamined = $totalExamined;
        $this->totalDeleted = $totalDeleted;
        $this->totalPreserved = $totalPreserved;
        $this->deletedByModel = $deletedByModel;
        $this->preservedByModel = $preservedByModel;
        $this->isDryRun = $isDryRun;
    }

    public function getModelsAffected(): int
    {
        return count($this->deletedByModel);
    }

    public function getSummary(): string
    {
        $mode = $this->isDryRun ? '[DRY RUN] Would delete' : 'Deleted';

        return sprintf(
            '%s %d of %d versions across %d model type(s)',
            $mode,
            $this->totalDeleted,
            $this->totalExamined,
            $this->getModelsAffected()
        );
    }
}
