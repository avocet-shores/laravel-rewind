<?php

namespace AvocetShores\LaravelRewind\Commands;

use AvocetShores\LaravelRewind\Services\VersionPruner;
use Illuminate\Console\Command;

class PruneVersionsCommand extends Command
{
    protected $signature = 'rewind:prune
        {--days= : Delete versions older than this many days}
        {--keep= : Keep only the last N versions per model instance}
        {--model=* : Restrict pruning to specific model type(s)}
        {--pretend : Show what would be deleted without actually deleting}
        {--force : Skip confirmation prompt}';

    protected $description = 'Prune old rewind version records.';

    public function handle(VersionPruner $pruner): int
    {
        $days = $this->option('days') ?? config('rewind.prune_older_than_days');
        $keep = $this->option('keep') ?? config('rewind.prune_keep_versions');
        $modelTypes = array_filter($this->option('model'));
        $pretend = $this->option('pretend');
        $force = $this->option('force');

        $days = $days !== null ? (int) $days : null;
        $keep = $keep !== null ? (int) $keep : null;

        if ($days === null && $keep === null) {
            $this->error('You must specify at least one of --days or --keep (or set defaults in config/rewind.php).');

            return 1;
        }

        if (! $pretend && ! $force && ! $this->confirm('This will permanently delete rewind version records. Are you sure?')) {
            $this->info('Pruning cancelled.');

            return 0;
        }

        $result = $pruner->prune(
            keepCount: $keep,
            olderThanDays: $days,
            modelTypes: $modelTypes ?: null,
            pretend: $pretend,
        );

        if ($result->totalDeleted === 0) {
            $this->info('No version records matched the pruning criteria.');

            return 0;
        }

        if ($pretend) {
            $this->info("[Pretend] Would delete {$result->totalDeleted} version record(s).");
        } else {
            $this->info("Successfully pruned {$result->totalDeleted} version record(s).");
        }

        if (count($result->deletedPerModelType) > 1) {
            foreach ($result->deletedPerModelType as $modelType => $count) {
                $this->line("  - {$modelType}: {$count}");
            }
        }

        return 0;
    }
}
