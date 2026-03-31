<?php

namespace AvocetShores\LaravelRewind\Dto;

class VersionDiff
{
    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changed  Attributes present in both versions with different values.
     * @param  array<string, mixed>  $added  Attributes only present in the target version.
     * @param  array<string, mixed>  $removed  Attributes only present in the source version.
     */
    public function __construct(
        public array $changed = [],
        public array $added = [],
        public array $removed = [],
    ) {}

    /**
     * Build a VersionDiff by comparing two attribute arrays.
     */
    public static function fromAttributes(array $from, array $to): self
    {
        $changed = [];
        $added = [];
        $removed = [];

        $allKeys = array_unique(array_merge(array_keys($from), array_keys($to)));

        foreach ($allKeys as $key) {
            $inFrom = array_key_exists($key, $from);
            $inTo = array_key_exists($key, $to);

            if ($inFrom && $inTo) {
                if ($from[$key] !== $to[$key]) {
                    $changed[$key] = ['old' => $from[$key], 'new' => $to[$key]];
                }
            } elseif (! $inFrom && $inTo) {
                $added[$key] = $to[$key];
            } else {
                $removed[$key] = $from[$key];
            }
        }

        return new self($changed, $added, $removed);
    }

    /**
     * Whether the two versions are identical.
     */
    public function isEmpty(): bool
    {
        return empty($this->changed) && empty($this->added) && empty($this->removed);
    }
}
