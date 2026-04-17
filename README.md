# Laravel Rewind

[![Latest Version on Packagist](https://img.shields.io/packagist/v/avocet-shores/laravel-rewind.svg?style=flat-square)](https://packagist.org/packages/avocet-shores/laravel-rewind)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/avocet-shores/laravel-rewind/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/avocet-shores/laravel-rewind/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Coverage Status](https://img.shields.io/codecov/c/github/avocet-shores/laravel-rewind?style=flat-square)](https://app.codecov.io/gh/avocet-shores/laravel-rewind/)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/avocet-shores/laravel-rewind/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/avocet-shores/laravel-rewind/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/avocet-shores/laravel-rewind.svg?style=flat-square)](https://packagist.org/packages/avocet-shores/laravel-rewind)

Full version control for your Eloquent models. Rewind, fast-forward, restore, diff, and query point-in-time state.

Under the hood, Rewind stores a mix of partial diffs and full snapshots. You get the storage efficiency of diffs with the reconstruction speed of snapshots, and the interval is configurable to suit your needs.

```php
use AvocetShores\LaravelRewind\Facades\Rewind;

$post->update(['title' => 'Updated Title']);

Rewind::rewind($post);       // Back to 'Old Title'
Rewind::fastForward($post);  // Forward to 'Updated Title'
Rewind::goTo($post, 3);      // Jump to any version
Rewind::restore($post, 1);   // Create a new version from v1's state
```

## Why Rewind?

- **Hybrid storage engine.** Diffs between snapshots keep storage small. Snapshots at configurable intervals keep reconstruction fast. You control the trade-off.
- **Thread-safe.** Cache-based locking prevents version sequence breaks during concurrent writes.
- **Non-destructive history.** Edits on older versions, restores, and pruning all preserve the full audit trail.
- **Batch versioning.** Group changes across multiple models into a single logical revision.
- **Built for real workloads.** Queued version creation, automatic pruning, and a cost-based approach engine that picks the fastest reconstruction path.

## Installation

```bash
composer require avocet-shores/laravel-rewind
```

Publish and run the migrations, and publish the config:

```bash
php artisan vendor:publish --provider="AvocetShores\LaravelRewind\LaravelRewindServiceProvider"
php artisan migrate
```

## Getting Started

### 1. Add the trait

```php
use AvocetShores\LaravelRewind\Traits\Rewindable;

class Post extends Model
{
   use Rewindable;
}
```

### 2. Add the `current_version` column

```bash
php artisan rewind:add-version
```

This generates a migration that adds `current_version` to your model's table. Run `php artisan migrate` to apply it.

That's it. Your model's changes are now tracked automatically.

## Navigating History

```php
use AvocetShores\LaravelRewind\Facades\Rewind;

// Step backward/forward
Rewind::rewind($post, 2);    // Go back 2 versions
Rewind::fastForward($post);  // Go forward 1 version

// Jump to a specific version
Rewind::goTo($post, 5);

// Get the model's state at a specific point in time
$attributes = Rewind::versionAt($post, Carbon::parse('2025-01-15 14:30:00'));
```

## Restoring State

There are two ways to go back to a previous version, and the distinction matters:

`goTo()` moves the pointer. The model is updated to match the target version, but no new version record is created. Good for previewing or navigating.

`restore()` creates a new version with the target version's state. The history shows the restore happened. Good for audit trails and compliance.

```php
// Move the pointer (no audit trail of the move itself)
Rewind::goTo($post, 3);

// Create a new version from v3's state (audit trail preserved)
Rewind::restore($post, 3);
// $post is now at v8 (or whatever the next version is), with v3's attributes
// The version record has event_type 'restored' and meta['restored_from_version'] = 3
```

## Inspecting Changes

### Version history

```php
$versions = $post->versions;
```

### Diff between two versions

```php
$diff = Rewind::diff($post, 1, 5);

$diff->changed;   // ['title' => ['old' => 'Draft', 'new' => 'Published']]
$diff->added;     // Attributes only in v5
$diff->removed;   // Attributes only in v1
$diff->isEmpty(); // false
```

Works in either direction. `diff($post, 5, 1)` swaps old and new.

### Replay through history

Walk through a range of versions with the fully reconstructed state at each step:

```php
Rewind::replay($post, 1, 10, function (RewindVersion $version, array $attributes) {
    // $version is the RewindVersion record (with meta, event_type, etc.)
    // $attributes is the complete model state at that version
});
```

Callback return values are collected into a `Collection`, so you can use it as a map:

```php
$titles = Rewind::replay($post, 1, 10, function (RewindVersion $version, array $attributes) {
    return $attributes['title'];
});

// Collection(['Draft', 'Review', 'Published', ...])
```

Works in reverse too. `replay($post, 10, 1)` walks backward from v10 to v1.

State is reconstructed incrementally, not independently per version, so this stays fast even over large ranges.

### Build a specific version's attributes

Diffs don't always contain all the data for a version. This method reconstructs the full attribute set:

```php
$attributes = Rewind::getVersionAttributes($post, 7);
```

### Clone a model at a version

```php
$clone = Rewind::cloneModel($post, 5);
```

### Query scopes

```php
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Enums\VersionEventType;

RewindVersion::forModel($post)->get();
RewindVersion::byUser($userId)->get();
RewindVersion::ofType(VersionEventType::Updated)->get();
RewindVersion::betweenDates($startDate, $endDate)->get();
RewindVersion::betweenVersions(1, 10)->get();

// Chain them together
RewindVersion::forModel($post)
    ->ofType(VersionEventType::Updated)
    ->byUser($userId)
    ->get();
```

## Tracking State Transitions

If your model has fields that represent state (like an order's status or payment status), Rewind can track each transition structurally. You get a queryable history of when and how states changed, separate from general attribute versioning.

### Define state fields

```php
use AvocetShores\LaravelRewind\Traits\Rewindable;

class Order extends Model
{
   use Rewindable;

   protected array $rewindStateFields = ['status', 'payment_status'];
}
```

Only fields listed in `$rewindStateFields` are tracked as transitions. All other attributes continue to be versioned normally.

### Querying transitions

```php
// Find versions where status became 'shipped'
$order->versions()->whereStateBecame('status', 'shipped')->get();

// Find versions where status transitioned away from 'pending'
$order->versions()->whereStateWas('status', 'pending')->get();

// Find every version where status changed at all
$order->versions()->whereStateChanged('status')->get();

// Match an exact from/to transition
$order->versions()->whereStateTransition('status', 'pending', 'shipped')->get();
```

`whereStateTransition` supports wildcards. Pass `null` for either direction to match any value:

```php
// Any transition that ended at 'shipped', regardless of where it came from
$order->versions()->whereStateTransition('status', null, 'shipped')->get();
```

These compose with existing scopes:

```php
$order->versions()
    ->whereStateBecame('status', 'shipped')
    ->byUser($userId)
    ->get();
```

### State history

Get a clean timeline of transitions for a specific field:

```php
$history = $order->stateHistory('status');

// [
//     ['version' => 1, 'from' => null,       'to' => 'pending',    'created_at' => ...],
//     ['version' => 2, 'from' => 'pending',   'to' => 'processing', 'created_at' => ...],
//     ['version' => 3, 'from' => 'processing', 'to' => 'shipped',   'created_at' => ...],
// ]
```

> State transitions work with amend mode. If multiple changes to a state field happen inside `amendCurrentVersion`, the transition collapses to the original `from` and the final `to`.

## Controlling What's Tracked

### Exclude attributes

```php
public static function excludedFromVersioning(): array
{
    return ['password', 'api_token'];
}
```

### Amend the current version

Sometimes you want to save a change without creating a new version. Maybe you're bumping a counter or syncing a denormalized field.

```php
Rewind::amendCurrentVersion(function () {
    $post->update(['view_count' => $post->view_count + 1]);
});
```

The changed attributes are folded into the current version's `old_values` and `new_values`. No new version row is created, but `goTo()`, `rewind()`, and `diff()` still work as expected.

> If an attribute should _never_ appear in version history, use `excludedFromVersioning()` instead. `amendCurrentVersion` is for attributes you still want tracked, just not as a separate version.

### Attach metadata

Record why a change was made:

```php
Rewind::withMeta(['reason' => 'Bulk price update', 'ticket' => 'JIRA-123']);
$product->update(['price' => 29.99]);
```

Metadata is stored in the version's `meta` field and automatically cleared after version creation.

### Event type tracking

Each version records the event that created it: `created`, `updated`, `deleted`, or `restored`.

```php
$creates = $post->versions()->where('event_type', VersionEventType::Created->value)->get();
```

### Initialize a v1 without changes

If you have an existing model and want to create a baseline version record:

```php
$post->initVersion();
```

## Working With Multiple Models

Batch versioning groups changes across models under a shared identifier:

```php
$batchUuid = Rewind::batch(function () {
    $order->update(['status' => 'shipped']);
    $item->update(['shipped_at' => now()]);
});

// Query all versions in the batch
$versions = RewindVersion::inBatch($batchUuid)->get();
```

## Managing Storage

### Pruning old versions

```bash
# Keep the last 50 versions per model
php artisan rewind:prune --keep=50

# Delete versions older than a year
php artisan rewind:prune --days=365

# Combine both (--keep protects recent versions regardless of age)
php artisan rewind:prune --keep=50 --days=365

# Prune a specific model type
php artisan rewind:prune --keep=50 --model=App\\Models\\Post

# Dry run
php artisan rewind:prune --keep=50 --pretend
```

When versions are pruned, Rewind automatically converts the new oldest remaining version into a full snapshot so navigation continues to work.

Schedule it:

```php
Schedule::command('rewind:prune --keep=50 --force')->daily();
```

You can set defaults for `--keep` and `--days` in `config/rewind.php` via `prune_keep_versions` and `prune_older_than_days`.

### Automatic version limits

Cap versions per model:

```php
class Post extends Model
{
    use Rewindable;

    protected static int $maxRewindVersions = 30;
}
```

Or set a global default via the `max_versions` config key. The per-model property takes precedence.

## Configuration

### Custom version model

Extend `RewindVersion` with your own model:

```php
// config/rewind.php
'version_model' => App\Models\CustomRewindVersion::class,
```

Your model must extend `AvocetShores\LaravelRewind\Models\RewindVersion`.

### Queued version creation

For high-write models, dispatch version creation to a queue:

```php
// config/rewind.php
'listener_should_queue' => true,
```

Queue retry behavior is configurable via the `queue` config key.

### Lock timeout handling

When a cache lock can't be acquired, behavior is configurable via `on_lock_timeout`:

- `log` (default): Logs an error silently.
- `event`: Dispatches a `RewindVersionLockTimeout` event for custom handling.
- `throw`: Throws a `LockTimeoutRewindException`. Useful with queued listeners since it triggers Laravel's retry mechanism.

### Snapshot interval

Controls how often full snapshots are stored vs. partial diffs. Default is every 10 versions. Higher values save storage at the cost of longer reconstruction times.

```php
// config/rewind.php
'snapshot_interval' => 10,
```

## How It Works

Rewind maintains a linear, non-destructive history. Here's what happens when you edit a model while on an older version:

1. Create a post, then update it. You're at v2.
2. Rewind to v1.
3. Update the post again.

Rewind uses the previous head version (v2) as the `old_values` for the new version (v3), creates a full snapshot, and marks v3 as the new head:

```php
[
    'version' => 3,
    'old_values' => [
        'title' => 'New Title', // From v2, not v1
    ],
    'new_values' => [
        'title' => 'Rewind is Awesome!',
    ],
]
```

The history always reads as if you updated from the previous head. You can jump around freely without losing data.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jared Cannon](https://github.com/jared-cannon)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
