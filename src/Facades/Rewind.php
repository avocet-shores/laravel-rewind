<?php

namespace AvocetShores\LaravelRewind\Facades;

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
 */
class Rewind extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-rewind-manager';
    }
}
