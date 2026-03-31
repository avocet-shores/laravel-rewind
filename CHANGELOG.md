# Changelog

All notable changes to `laravel-rewind` will be documented in this file.

## v0.8.0 - 2026-03-30

### New Features

* **Version diff/comparison API** -- Compare any two versions with `Rewind::diff($model, $from, $to)`, returns a structured `VersionDiff` DTO with `changed`, `added`, and `removed` attributes.
* **Custom metadata per version** -- Attach arbitrary key-value data to versions via `Rewind::withMeta(['reason' => '...'])`. Stored in a new `meta` JSON column. Metadata is automatically cleared after version creation.
* **Event type tracking** -- Each version now records its `event_type` (`created`, `updated`, or `deleted`) in a dedicated column, making version history queryable by action type.
* **Query scopes on RewindVersion** -- New scopes: `forModel()`, `byUser()`, `ofType()`, `betweenDates()`, `betweenVersions()` for filtering version records.
* **Version pruning system** -- New `rewind:prune` command with `--keep`, `--days`, `--model`, `--pretend`, and `--force` options. Automatically converts the new oldest version to a snapshot to preserve navigability.
* **Auto-pruning** -- Per-model `$maxRewindVersions` property and global `max_versions` config. Pruning is batched using `snapshot_interval` as a buffer to amortize transaction costs.
* **Configurable lock timeout handling** -- New `on_lock_timeout` config with `log`, `event`, and `throw` modes. New `RewindVersionLockTimeout` event and `LockTimeoutRewindException`.
* **Composite database index** -- `(model_type, model_id, version)` for faster version-scoped queries.

### Bug Fixes

* Fix soft delete version timestamp mismatch -- moved version creation to `deleted` event to capture exact database timestamp.
* Fix queued listener serialization -- capture changes and `wasRecentlyCreated` at dispatch time so they survive `SerializesModels` round-trips.
* Fix version pruning with gaps -- iterate actual version records instead of sequential integers.
* Add transaction safety -- version creation, `current_version` update, and auto-prune are now atomic.
* Add cache lock to `goTo()` for concurrency safety.
* Use `saveQuietly()` when updating `current_version` to prevent unintended model events.

### Architecture

* New `StateBuilder` service consolidating all state reconstruction logic.
* New `RewindManagerInterface` contract for dependency injection.
* New `VersionPruner` service, `PruneResult` DTO, `RewindContext` singleton, `SchemaHelper` utility.
* `ApproachEngine` now accepts `Collection` for use outside of model context.
* Internal classes marked with `@internal` to define public API boundary.

### Housekeeping

* Removed `version` field from `composer.json` (Packagist reads git tags).
* Changed `minimum-stability` from `dev` to `stable`.
* Added `CONTRIBUTING.md`.

### Migration Notes

This release adds two new nullable columns to the `rewind_versions` table: `event_type` (string) and `meta` (json). If you have already published the migration, you will need to add these columns manually:

```php
Schema::table(config('rewind.table_name', 'rewind_versions'), function (Blueprint $table) {
    $table->string('event_type')->nullable();
    $table->json('meta')->nullable();
});
```

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.7.4...v0.8.0

## v0.7.4 - 2025-11-07

### What's Changed

* Fix getDirty() vs getChanges() bug in saved event by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/36
* Fix Rewindable trait firing model observers twice by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/38
* Bump version to 0.7.4 by @jared-cannon in https://github.com/avocet-shores/laravel-rewind/pull/39

**Full Changelog**: https://github.com/avocet-shores/laravel-rewind/compare/v0.7.3...v0.7.4

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
