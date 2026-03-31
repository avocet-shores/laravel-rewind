<?php

namespace AvocetShores\LaravelRewind\Facades;

use AvocetShores\LaravelRewind\Dto\VersionDiff;
use AvocetShores\LaravelRewind\Services\RewindManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @see RewindManager
 *
 * @method static int rewind(Model $model, int $steps = 1) Rewind by a specified number of steps, returns actual version
 * @method static int fastForward(Model $model, int $steps = 1) Fast-forward by a specified number of steps, returns actual version
 * @method static int goTo(Model $model, int $targetVersion) Jump to a specific version, returns version number
 * @method static Model cloneModel(Model $model, int $targetVersion) Clone a model at a specific version
 * @method static array getVersionAttributes(Model $model, int $targetVersion) Get the attributes of a model at a specific version
 * @method static VersionDiff diff(Model $model, int $fromVersion, int $toVersion) Compare two versions and return a structured diff
 * @method static int restore(Model $model, int $targetVersion) Create a new version with the state from a previous version
 * @method static array versionAt(Model $model, \Illuminate\Support\Carbon $timestamp) Get attributes at a point in time
 * @method static void withMeta(array $meta) Set metadata to attach to the next version created
 * @method static string batch(callable $callback) Group multiple changes into a single batch, returns batch UUID
 * @method static mixed amendCurrentVersion(callable $callback) Execute callback where changes amend the current version
 */
class Rewind extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-rewind-manager';
    }
}
