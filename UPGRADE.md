# Upgrading from 0.x to 1.0

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## Migration

A new migration adds the `batch_uuid` column to the versions table. Publish and run it:

```bash
php artisan vendor:publish --tag=laravel-rewind-migrations
php artisan migrate
```

If you previously published migrations, the new migration file will be added alongside your existing ones. The migration safely checks for column existence before adding.

## New Config Keys

Add these to your published `config/rewind.php` if you want to use them (both are optional):

```php
// Custom version model (must extend RewindVersion)
'version_model' => null,
```

## New Features

### Batch Versioning

Group related changes under a shared identifier:

```php
use AvocetShores\LaravelRewind\Facades\Rewind;

$batchUuid = Rewind::batch(function () {
    $order->update(['status' => 'shipped']);
    $item->update(['shipped_at' => now()]);
});

// Query all versions in the batch
$versions = RewindVersion::inBatch($batchUuid)->get();
```

### Non-Destructive Restore

Create a new version from a previous version's state instead of moving the pointer:

```php
// Post is at v5. Restore to v2's state as a new v6.
Rewind::restore($post, 2);
// $post->current_version === 6
// v6 has event_type 'restored' and meta['restored_from_version'] = 2
```

This differs from `goTo()` which moves the pointer without creating a version record.

### Custom Version Model

Extend `RewindVersion` with custom columns, scopes, or accessors:

```php
// app/Models/CustomRewindVersion.php
class CustomRewindVersion extends \AvocetShores\LaravelRewind\Models\RewindVersion
{
    // Add custom behavior
}

// config/rewind.php
'version_model' => \App\Models\CustomRewindVersion::class,
```

### New Enum Case

`VersionEventType::Restored` is added for versions created via `Rewind::restore()`.

## Breaking Changes

None. All existing public API methods, config keys, events, and exceptions remain unchanged.

## API Stability

Starting with v1.0.0, the following are stable public API per semver:

- `Rewind` facade methods (`rewind`, `fastForward`, `goTo`, `cloneModel`, `getVersionAttributes`, `diff`, `withMeta`, `batch`, `restore`)
- `RewindManagerInterface` contract
- `Rewindable` trait public methods
- `RewindVersion` model (public methods, relationships, scopes)
- All events (`RewindVersionCreating`, `RewindVersionCreated`, `RewindVersionLockTimeout`)
- All exceptions
- `VersionDiff`, `PruneResult` DTOs
- `VersionEventType` enum
- All config keys in `config/rewind.php`

Classes marked `@internal` (`StateBuilder`, `ApproachEngine`, `SchemaHelper`, `ApproachPlan`, `ApproachMethod`, `RewindContext`) may change without a major version bump.
