# Upgrading to 0.9.0

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## Migration

A new migration adds the `batch_uuid` column to the versions table:

```bash
php artisan vendor:publish --tag=laravel-rewind-migrations
php artisan migrate
```

The migration checks for column existence before adding, so it's safe to run alongside previously published migrations.

## New Config Keys

Add to your published `config/rewind.php` if needed (optional):

```php
// Custom version model (must extend RewindVersion)
'version_model' => null,
```

## New Features

### Batch Versioning

Group related changes under a shared identifier:

```php
$batchUuid = Rewind::batch(function () {
    $order->update(['status' => 'shipped']);
    $item->update(['shipped_at' => now()]);
});

$versions = RewindVersion::inBatch($batchUuid)->get();
```

### Non-Destructive Restore

Create a new version from a previous version's state instead of moving the pointer:

```php
// Post is at v5. Restore to v2's state as a new v6.
Rewind::restore($post, 2);
// v6 has event_type 'restored' and meta['restored_from_version'] = 2
```

This differs from `goTo()` which moves the pointer without creating a version record.

### Custom Version Model

Extend `RewindVersion` with custom columns, scopes, or accessors:

```php
class CustomRewindVersion extends \AvocetShores\LaravelRewind\Models\RewindVersion
{
    // Add custom behavior
}

// config/rewind.php
'version_model' => \App\Models\CustomRewindVersion::class,
```

### New Enum Case

`VersionEventType::Restored` for versions created via `Rewind::restore()`.

## Breaking Changes

None.
