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
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-rewind')
            ->hasConfigFile()
            ->hasMigration('create_rewind_versions_table')
            ->hasCommand(AddVersionTrackingColumnCommand::class);
    }

    public function registeringPackage(): void
    {
        $this->app->bind('laravel-rewind-manager', RewindManager::class);
    }

    public function bootingPackage(): void
    {
        $async = config('rewind.async_listener', false);

        if ($async) {
            Event::listen(
                RewindVersionCreating::class,
                [CreateRewindVersionQueued::class, 'handle']
            );
        } else {
            Event::listen(
                RewindVersionCreating::class,
                [CreateRewindVersion::class, 'handle']
            );
        }
    }
}
