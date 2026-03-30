<?php

namespace AvocetShores\LaravelRewind\Contracts;

use AvocetShores\LaravelRewind\Exceptions\CurrentVersionColumnMissingException;
use AvocetShores\LaravelRewind\Exceptions\LaravelRewindException;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use Illuminate\Database\Eloquent\Model;

interface RewindManagerInterface
{
    /**
     * Rewind by a specified number of steps.
     * Returns the actual version the model was set to.
     *
     * @throws LaravelRewindException
     */
    public function rewind($model, int $steps = 1): int;

    /**
     * Fast-forward by a specified number of steps.
     * Returns the actual version the model was set to.
     *
     * @throws LaravelRewindException
     */
    public function fastForward($model, int $steps = 1): int;

    /**
     * Jump directly to a specified version.
     * Returns the version number.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     */
    public function goTo($model, int $targetVersion): int;

    /**
     * Replicates the given model and fills it with the attributes from the specified version.
     *
     * @throws LaravelRewindException
     */
    public function cloneModel(Model $model, int $targetVersion): Model;

    /**
     * Get the attributes of a model at a specific version.
     *
     * @throws LaravelRewindException
     */
    public function getVersionAttributes(Model $model, int $targetVersion): array;
}
