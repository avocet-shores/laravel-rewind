<?php

namespace AvocetShores\LaravelRewind\Services;

/**
 * @internal Not part of the public API. Subject to change without notice.
 */
class RewindContext
{
    protected array $meta = [];

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
     */
    public function flush(): array
    {
        $meta = $this->meta;
        $this->meta = [];

        return $meta;
    }
}
