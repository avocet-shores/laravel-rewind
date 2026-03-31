<?php

namespace AvocetShores\LaravelRewind\Services;

use AvocetShores\LaravelRewind\Enums\VersionEventType;

/**
 * @internal Not part of the public API. Subject to change without notice.
 */
class RewindContext
{
    protected array $meta = [];

    protected ?string $batchUuid = null;

    protected ?VersionEventType $eventTypeOverride = null;

    protected bool $forceVersion = false;

    protected int $versioningDisabledDepth = 0;

    public function set(array $meta): void
    {
        $this->meta = $meta;
    }

    public function get(): array
    {
        return $this->meta;
    }

    /**
     * Return current meta and reset it.
     * Note: batch UUID is intentionally NOT cleared here — it persists
     * for the duration of the batch callback across multiple version creations.
     */
    public function flush(): array
    {
        $meta = $this->meta;
        $this->meta = [];

        return $meta;
    }

    public function setBatchUuid(?string $uuid): void
    {
        $this->batchUuid = $uuid;
    }

    public function getBatchUuid(): ?string
    {
        return $this->batchUuid;
    }

    public function clearBatch(): void
    {
        $this->batchUuid = null;
    }

    public function setEventTypeOverride(?VersionEventType $type): void
    {
        $this->eventTypeOverride = $type;
    }

    public function setForceVersion(bool $force): void
    {
        $this->forceVersion = $force;
    }

    /**
     * Return and clear the force version flag.
     */
    public function flushForceVersion(): bool
    {
        $force = $this->forceVersion;
        $this->forceVersion = false;

        return $force;
    }

    /**
     * Return and clear the event type override.
     */
    public function flushEventTypeOverride(): ?VersionEventType
    {
        $override = $this->eventTypeOverride;
        $this->eventTypeOverride = null;

        return $override;
    }

    public function disableVersioning(): void
    {
        $this->versioningDisabledDepth++;
    }

    public function enableVersioning(): void
    {
        if ($this->versioningDisabledDepth > 0) {
            $this->versioningDisabledDepth--;
        }
    }

    public function isVersioningDisabled(): bool
    {
        return $this->versioningDisabledDepth > 0;
    }
}
