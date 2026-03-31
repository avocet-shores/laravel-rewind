<?php

namespace AvocetShores\LaravelRewind\Contracts;

use AvocetShores\LaravelRewind\Dto\VersionDiff;
use AvocetShores\LaravelRewind\Exceptions\CurrentVersionColumnMissingException;
use AvocetShores\LaravelRewind\Exceptions\LaravelRewindException;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;

interface RewindManagerInterface
{
    /**
     * Rewind by a specified number of steps.
     * Returns the actual version the model was set to.
     *
     * @throws LaravelRewindException
     */
    public function rewind(Model $model, int $steps = 1): int;

    /**
     * Fast-forward by a specified number of steps.
     * Returns the actual version the model was set to.
     *
     * @throws LaravelRewindException
     */
    public function fastForward(Model $model, int $steps = 1): int;

    /**
     * Jump directly to a specified version.
     * Returns the version number.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     * @throws LockTimeoutException
     */
    public function goTo(Model $model, int $targetVersion): int;

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

    /**
     * Compare two versions and return a structured diff.
     *
     * @throws LaravelRewindException
     */
    public function diff(Model $model, int $fromVersion, int $toVersion): VersionDiff;

    /**
     * Non-destructive revert: creates a new version with the state from a previous version.
     * Returns the new version number.
     *
     * @throws ModelNotRewindableException
     * @throws VersionDoesNotExistException
     * @throws CurrentVersionColumnMissingException
     */
    public function restore(Model $model, int $targetVersion): int;

    /**
     * Set metadata to attach to the next version created.
     */
    public function withMeta(array $meta): void;

    /**
     * Group multiple model changes into a single logical batch.
     * All versions created within the callback share a batch UUID.
     *
     * @return string The batch UUID
     *
     * @throws \LogicException If called while already inside a batch
     */
    public function batch(callable $callback): string;
}
