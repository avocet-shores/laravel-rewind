<?php

namespace AvocetShores\LaravelRewind\Commands;

use AvocetShores\LaravelRewind\Services\PruneService;
use Illuminate\Console\Command;

class PruneRewindVersionsCommand extends Command
{
    protected $signature = 'rewind:prune
                            {--days= : Keep only versions created within the last X days}
                            {--keep= : Keep only the last X versions per model}
                            {--model= : Only prune versions for a specific model type}
                            {--dry-run : Preview what would be deleted without actually deleting}';

    protected $description = 'Prune old rewind versions based on retention policies';

    public function handle(PruneService $pruneService): int
    {
        $this->info('Starting Rewind version pruning...');
        $this->newLine();

        // Build options from command arguments and config
        $options = [
            'days' => $this->option('days') ? (int) $this->option('days') : null,
            'keep' => $this->option('keep') ? (int) $this->option('keep') : null,
            'model' => $this->option('model'),
            'dry_run' => $this->option('dry-run'),
        ];

        // Validate that at least one retention policy is set
        $retentionDays = $options['days'] ?? config('rewind.pruning.retention_days');
        $retentionCount = $options['keep'] ?? config('rewind.pruning.retention_count');

        if ($retentionDays === null && $retentionCount === null) {
            $this->warn('No retention policy configured.');
            $this->info('Set retention_days or retention_count in config/rewind.php, or use --days or --keep options.');

            return 1;
        }

        // Display active retention policies
        $this->displayRetentionPolicies($retentionDays, $retentionCount, $options['model']);

        // Execute pruning
        $result = $pruneService->prune($options);

        // Display results
        $this->newLine();
        $this->displayResults($result);

        return 0;
    }

    protected function displayRetentionPolicies(?int $days, ?int $count, ?string $model): void
    {
        $this->info('Active retention policies:');

        if ($days !== null) {
            $this->line("  • Keep versions from last {$days} days");
        }

        if ($count !== null) {
            $this->line("  • Keep last {$count} versions per model");
        }

        if ($model) {
            $this->line("  • Target model: {$model}");
        }

        $this->line('  • Preserve version 1: '.($this->formatBoolean(config('rewind.pruning.keep_version_one', true))));
        $this->line('  • Preserve critical snapshots: '.($this->formatBoolean(config('rewind.pruning.keep_snapshots', true))));

        $this->newLine();
    }

    protected function displayResults($result): void
    {
        if ($result->isDryRun) {
            $this->warn('DRY RUN MODE - No versions were actually deleted');
            $this->newLine();
        }

        // Summary
        $this->info($result->getSummary());
        $this->newLine();

        // Detailed breakdown
        if ($result->totalDeleted > 0) {
            $action = $result->isDryRun ? 'Would delete' : 'Deleted';
            $this->line("<fg=yellow>{$action} by model type:</>");

            foreach ($result->deletedByModel as $modelType => $count) {
                $this->line("  • {$modelType}: {$count} version(s)");
            }

            $this->newLine();
        }

        // Preserved counts
        if ($result->totalPreserved > 0) {
            $this->line('<fg=green>Preserved by model type:</>');

            foreach ($result->preservedByModel as $modelType => $count) {
                $this->line("  • {$modelType}: {$count} version(s)");
            }

            $this->newLine();
        }

        // Final message
        if ($result->isDryRun && $result->totalDeleted > 0) {
            $this->info('Run without --dry-run to actually delete these versions.');
        } elseif ($result->totalDeleted > 0) {
            $this->info('Pruning completed successfully!');
        } else {
            $this->info('No versions matched the retention criteria for deletion.');
        }
    }

    protected function formatBoolean(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
