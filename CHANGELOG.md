# Changelog

All notable changes to `laravel-rewind` will be documented in this file.

## [Unreleased]

### Added

- Support for UUID and ULID primary keys (#17)
- Comprehensive test suite for UUID/ULID models
- Upgrade migration stub for existing installations

### Changed

- **BREAKING**: `model_id` column changed from `unsignedBigInteger` to `string(36)` to support UUID/ULID primary keys
- Existing users must run the upgrade migration (see upgrade instructions below)

### Upgrade Instructions

For existing installations, run this migration to upgrade your database:

```bash
# Copy the upgrade migration stub
cp vendor/avocet-shores/laravel-rewind/database/migrations/upgrade_rewind_versions_for_uuid_support.php.stub \
  database/migrations/$(date +%Y_%m_%d_%H%M%S)_upgrade_rewind_versions_for_uuid_support.php

# Run migrations
php artisan migrate
```

**Note**: This change is fully backward compatible - integer IDs will continue to work as they are stored as strings.

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
