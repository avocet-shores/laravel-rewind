<?php

namespace AvocetShores\LaravelRewind;

use AvocetShores\LaravelRewind\Commands\AddVersionTrackingColumnCommand;
use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Listeners\CreateRewindVersion;
use AvocetShores\LaravelRewind\Listeners\CreateRewindVersionQueued;
use AvocetShores\LaravelRewind\Services\RewindManager;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRewindServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rewind')
            ->hasConfigFile()
            ->hasMigration('create_rewind_versions_table')
            ->hasCommand(AddVersionTrackingColumnCommand::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Publish the UUID upgrade migration separately for existing users only
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations/upgrade_rewind_versions_for_uuid_support.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_upgrade_rewind_versions_for_uuid_support.php'),
            ], 'laravel-rewind-uuid-upgrade');
        }
    }

    public function registeringPackage(): void
    {
        $this->app->bind('laravel-rewind-manager', RewindManager::class);
    }

    public function bootingPackage(): void
    {
        $async = config('rewind.listener_should_queue', false);

        if ($async) {
            Event::listen(
                RewindVersionCreating::class,
                CreateRewindVersionQueued::class
            );
        } else {
            Event::listen(
                RewindVersionCreating::class,
                CreateRewindVersion::class
            );
        }
    }
}
