# Changelog

All notable changes to `laravel-rewind` will be documented in this file.

## [Unreleased]

### Added

* Version pruning system with `rewind:prune` command
* Configurable retention policies based on age and count
* Smart preservation of critical snapshots for efficient reconstruction
* Dry-run mode and per-model filtering
* `PruneService` and `PruneResult` for programmatic access

## v0.7.3 - 2025-10-21

### What's Changed

* Bump dependabot/fetch-metadata from 2.3.0 to 2.4.0 by @dependabot[bot] in https://github.com/avocet-shores/laravel-rewind/pull/27
* Bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/avocet-shores/laravel-rewind/pull/29
* Update PHP version requirement to include 8.4 by @danielrona in https://github.com/avocet-shores/laravel-rewind/pull/31
* Fix excluded attributes not working with SoftDeletes models by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/33
* Bump version to 0.7.3 by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/34

### New Contributors

* @danielrona made their first contribution in https://github.com/avocet-shores/laravel-rewind/pull/31

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.7.2...v0.7.3

## v0.7.2 - 2025-02-28

### What's Changed

* Append version 12.0 to the package ‘illuminate/contracts’. by @fdjkgh580 in https://github.com/avocet-shores/laravel-rewind/pull/23

### New Contributors

* @fdjkgh580 made their first contribution in https://github.com/avocet-shores/laravel-rewind/pull/23

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.7.1...v0.7.2

## v0.7.1 - 2025-02-13

### What's Changed

#### Bug Fixes and Improvements

* Fix Delete Bug and Add Soft Delete Tracking by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/20

#### Docs

* docs: Fix Rewindable trait on model by @nilshee in https://github.com/avocet-shores/laravel-rewind/pull/19

### New Contributors

* @nilshee made their first contribution in https://github.com/avocet-shores/laravel-rewind/pull/19

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.7.0...v0.7.1

## v0.7.0 - 2025-01-24

### What's Changed

* Update Morph Relationship by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/12
* Concurrency config enhancements and current_version column requirements by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/9

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.6.0...v0.7.0
