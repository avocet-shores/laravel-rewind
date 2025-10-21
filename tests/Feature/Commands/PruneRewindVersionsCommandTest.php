<?php

use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);
});

it('exits with warning when no retention policy is configured', function () {
    config(['rewind.pruning.retention_days' => null]);
    config(['rewind.pruning.retention_count' => null]);

    artisan('rewind:prune')
        ->expectsOutput('No retention policy configured.')
        ->assertExitCode(1);
});

it('prunes versions using days option', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make some versions old
    $post->versions()
        ->whereIn('version', [2, 3])
        ->update(['created_at' => now()->subDays(35)]);

    artisan('rewind:prune', ['--days' => 30])
        ->expectsOutputToContain('Starting Rewind version pruning')
        ->expectsOutputToContain('Deleted 2 of 6 versions')
        ->assertExitCode(0);

    expect($post->fresh()->versions()->count())->toBe(4);
});

it('prunes versions using keep option', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 9; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    expect($post->versions()->count())->toBe(10);

    artisan('rewind:prune', ['--keep' => 5])
        ->expectsOutputToContain('Keep last 5 versions per model')
        ->expectsOutputToContain('Pruning completed successfully')
        ->assertExitCode(0);

    // Should keep version 1 (protected) + last 5 = 6 total
    expect($post->fresh()->versions()->count())->toBe(6);
});

it('shows dry run output without deleting', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    $post->versions()
        ->whereIn('version', [2, 3])
        ->update(['created_at' => now()->subDays(35)]);

    $initialCount = $post->versions()->count();

    artisan('rewind:prune', ['--days' => 30, '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN MODE')
        ->expectsOutputToContain('Would delete 2 of 6 versions')
        ->expectsOutputToContain('Run without --dry-run to actually delete')
        ->assertExitCode(0);

    // No versions should be deleted
    expect($post->fresh()->versions()->count())->toBe($initialCount);
});

it('filters by specific model type', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Make post version old
    $post->versions()->update(['created_at' => now()->subDays(35)]);

    // Update user to create a version
    $this->user->update(['name' => 'Jane Doe']);
    $this->user->versions()->update(['created_at' => now()->subDays(35)]);

    config(['rewind.pruning.keep_version_one' => false]);

    artisan('rewind:prune', ['--days' => 30, '--model' => Post::class])
        ->expectsOutputToContain('Target model: '.Post::class)
        ->assertExitCode(0);

    // Post version should be deleted, User version should remain
    expect($post->fresh()->versions()->count())->toBe(0)
        ->and($this->user->fresh()->versions()->count())->toBe(1);
});

it('displays retention policies correctly', function () {
    artisan('rewind:prune', ['--days' => 90, '--keep' => 100])
        ->expectsOutputToContain('Active retention policies:')
        ->expectsOutputToContain('Keep versions from last 90 days')
        ->expectsOutputToContain('Keep last 100 versions per model')
        ->expectsOutputToContain('Preserve version 1: Yes')
        ->expectsOutputToContain('Preserve critical snapshots: Yes')
        ->assertExitCode(0);
});

it('shows breakdown by model type', function () {
    $post1 = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post 1',
        'body' => 'Body 1',
    ]);

    $post2 = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post 2',
        'body' => 'Body 2',
    ]);

    // Make versions old
    $post1->versions()->update(['created_at' => now()->subDays(35)]);
    $post2->versions()->update(['created_at' => now()->subDays(35)]);

    config(['rewind.pruning.keep_version_one' => false]);

    artisan('rewind:prune', ['--days' => 30])
        ->expectsOutputToContain('Deleted by model type:')
        ->expectsOutputToContain(Post::class)
        ->assertExitCode(0);
});

it('handles no matching versions gracefully', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // All versions are recent
    artisan('rewind:prune', ['--days' => 30])
        ->expectsOutputToContain('No versions matched the retention criteria for deletion')
        ->assertExitCode(0);
});

it('uses config defaults when no options provided', function () {
    config(['rewind.pruning.retention_days' => 60]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    $post->versions()->update(['created_at' => now()->subDays(70)]);

    config(['rewind.pruning.keep_version_one' => false]);

    artisan('rewind:prune')
        ->expectsOutputToContain('Keep versions from last 60 days')
        ->assertExitCode(0);

    expect($post->fresh()->versions()->count())->toBe(0);
});

it('shows preserved counts by model type', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    $post->versions()
        ->whereIn('version', [2, 3])
        ->update(['created_at' => now()->subDays(35)]);

    artisan('rewind:prune', ['--days' => 30])
        ->expectsOutputToContain('Preserved by model type:')
        ->expectsOutputToContain(Post::class.': 4 version(s)')
        ->assertExitCode(0);
});
