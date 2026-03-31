<?php

namespace AvocetShores\LaravelRewind\Commands;

use AvocetShores\LaravelRewind\Traits\Rewindable;
use Illuminate\Console\Command;

class InitVersionsCommand extends Command
{
    protected $signature = 'rewind:init
        {model : The fully qualified model class name}
        {--chunk=100 : Number of records to process at a time}
        {--pretend : Show what would be created without actually creating}
        {--force : Skip confirmation prompt}';

    protected $description = 'Create initial version snapshots for existing model records that have no version history.';

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (! class_exists($modelClass)) {
            $this->error("Class [{$modelClass}] does not exist.");

            return 1;
        }

        if (! in_array(Rewindable::class, class_uses_recursive($modelClass))) {
            $this->error("Model [{$modelClass}] does not use the Rewindable trait.");

            return 1;
        }

        $pretend = $this->option('pretend');
        $force = $this->option('force');
        $chunkSize = (int) $this->option('chunk');

        $query = $modelClass::whereDoesntHave('versions');
        $total = $query->count();

        if ($total === 0) {
            $this->info('All records already have version history. Nothing to do.');

            return 0;
        }

        if ($pretend) {
            $this->info("[Pretend] Would create initial versions for {$total} record(s).");

            return 0;
        }

        if (! $force && ! $this->confirm("This will create initial version snapshots for {$total} record(s). Continue?")) {
            $this->info('Cancelled.');

            return 0;
        }

        $created = 0;

        // The progress bar total is approximate — concurrent processes may create versions
        // between the count() and chunkById() queries. The final $created count is accurate.
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $keyName = (new $modelClass)->getKeyName();

        $modelClass::whereDoesntHave('versions')
            ->chunkById($chunkSize, function ($models) use (&$created, $bar) {
                foreach ($models as $model) {
                    $model->initVersion();
                    $created++;
                    $bar->advance();
                }
            }, $keyName);

        $bar->finish();
        $this->newLine();
        $this->info("Created initial versions for {$created} record(s).");

        return 0;
    }
}
