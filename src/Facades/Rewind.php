<?php

namespace AvocetShores\LaravelRewind\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @see \AvocetShores\LaravelRewind\Services\RewindManager
 *
 * @method static void rewind(Model $model, int $steps = 1) Rewind by a specified number of steps
 * @method static void fastForward(Model $model, int $steps = 1) Fast-forward by a specified number of steps
 * @method static void goTo(Model $model, int $version) Jump to a specific version
 * @method static mixed cloneModel(Model $model, int $version) Clone a model at a specific version
 * @method static array getVersionAttributes(Model $model, int $version) Get the attributes of a model at a specific version
 */
class Rewind extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-rewind-manager';
    }
}
