<?php

use AvocetShores\LaravelRewind\Services\PruneService;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\Template;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a user for authentication
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);

    $this->pruneService = new PruneService;
});

it('returns empty result when no retention policy is configured', function () {
    config(['rewind.pruning.retention_days' => null]);
    config(['rewind.pruning.retention_count' => null]);

    $result = $this->pruneService->prune();

    expect($result->totalExamined)->toBe(0)
        ->and($result->totalDeleted)->toBe(0)
        ->and($result->totalPreserved)->toBe(0);
});

it('prunes versions older than retention days', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create 5 more versions
    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    expect($post->versions()->count())->toBe(6);

    // Manually update created_at for first 3 versions to be older than 30 days
    $post->versions()
        ->whereIn('version', [1, 2, 3])
        ->update(['created_at' => now()->subDays(35)]);

    // Prune versions older than 30 days
    $result = $this->pruneService->prune([
        'days' => 30,
    ]);

    // Version 1 should be preserved (keep_version_one = true by default)
    // Versions 2-3 should be deleted (older than 30 days)
    // Versions 4-6 should be preserved (newer than 30 days)
    expect($result->totalDeleted)->toBe(2)
        ->and($result->totalPreserved)->toBe(4)
        ->and($post->versions()->count())->toBe(4);
});

it('preserves version 1 when keep_version_one is true', function () {
    config(['rewind.pruning.keep_version_one' => true]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Make version 1 very old
    $post->versions()->where('version', 1)->update(['created_at' => now()->subYears(10)]);

    $result = $this->pruneService->prune(['days' => 1]);

    // Version 1 should be preserved despite being old
    expect($result->totalDeleted)->toBe(0)
        ->and($post->versions()->where('version', 1)->exists())->toBeTrue();
});

it('deletes version 1 when keep_version_one is false', function () {
    config(['rewind.pruning.keep_version_one' => false]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create another version
    $post->update(['title' => 'Version 2']);

    // Make version 1 old
    $post->versions()->where('version', 1)->update(['created_at' => now()->subDays(35)]);

    $result = $this->pruneService->prune(['days' => 30]);

    // Version 1 should be deleted
    expect($result->totalDeleted)->toBe(1)
        ->and($post->versions()->where('version', 1)->exists())->toBeFalse();
});

it('prunes versions beyond retention count', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create 9 more versions (total 10)
    for ($i = 1; $i <= 9; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    expect($post->versions()->count())->toBe(10);

    // Keep only last 5 versions
    $result = $this->pruneService->prune(['keep' => 5]);

    // Should delete oldest 5 versions, but version 1 is preserved
    // So: versions 2-5 deleted (4 versions), versions 1,6-10 kept (6 versions)
    expect($result->totalDeleted)->toBe(4)
        ->and($result->totalPreserved)->toBe(6)
        ->and($post->versions()->count())->toBe(6);
});

it('preserves snapshots with all diffs for reconstruction when keep_snapshots is true', function () {
    config(['rewind.pruning.keep_snapshots' => true]);
    config(['rewind.snapshot_interval' => 10]);
    config(['rewind.pruning.keep_version_one' => false]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create versions up to 20 (versions 1, 10, and 20 will be snapshots)
    for ($i = 2; $i <= 20; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make all versions old
    $post->versions()->update(['created_at' => now()->subDays(100)]);

    // Keep only last 5 versions (16-20)
    $result = $this->pruneService->prune(['keep' => 5]);

    // With keep_snapshots=true, should keep snapshot 10 and all versions from 10-20
    // This maintains reconstruction capability for the kept range
    // Versions 1-9 can be deleted (orphaning version 1 is okay as it's a complete snapshot)
    expect($post->versions()->pluck('version')->sort()->values()->toArray())
        ->toBe([10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
        ->and($result->totalDeleted)->toBe(9);

    // Verify version 10 is a snapshot (anchor point for reconstruction)
    expect($post->versions()->where('version', 10)->first()->is_snapshot)->toBeTrue();
});

it('allows deleting snapshots when keep_snapshots is false', function () {
    config(['rewind.pruning.keep_snapshots' => false]);
    config(['rewind.snapshot_interval' => 10]);
    config(['rewind.pruning.keep_version_one' => false]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create versions up to 20
    for ($i = 2; $i <= 20; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make all versions old
    $post->versions()->update(['created_at' => now()->subDays(100)]);

    // Keep only last 5 versions (16-20)
    $result = $this->pruneService->prune(['keep' => 5]);

    // With keep_snapshots=false, should only keep exactly 16-20 (5 versions)
    // Snapshots 10 can be deleted (no reconstruction capability maintained)
    expect($post->versions()->pluck('version')->sort()->values()->toArray())
        ->toBe([16, 17, 18, 19, 20])
        ->and($result->totalDeleted)->toBe(15);
});

it('applies both retention policies with most permissive winning', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create 10 more versions
    for ($i = 1; $i <= 10; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make first 5 versions old (35 days)
    $post->versions()
        ->whereIn('version', [1, 2, 3, 4, 5])
        ->update(['created_at' => now()->subDays(35)]);

    // Apply both policies: 30 days AND keep last 8
    // Versions 1-5 are old (would be deleted by age policy)
    // Keep last 8 means keep versions 4-11
    // Union of both: keep versions 1 (protected), 4-11
    $result = $this->pruneService->prune([
        'days' => 30,
        'keep' => 8,
    ]);

    // Versions 2-3 should be deleted (old AND not in last 8)
    // Version 1 is protected
    expect($result->totalDeleted)->toBe(2)
        ->and($post->versions()->whereIn('version', [2, 3])->count())->toBe(0);
});

it('handles dry run mode without deleting', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    $initialCount = $post->versions()->count();

    // Make some versions old
    $post->versions()
        ->whereIn('version', [2, 3])
        ->update(['created_at' => now()->subDays(35)]);

    $result = $this->pruneService->prune([
        'days' => 30,
        'dry_run' => true,
    ]);

    // Should report what would be deleted but not actually delete
    expect($result->isDryRun)->toBeTrue()
        ->and($result->totalDeleted)->toBe(2)
        ->and($post->versions()->count())->toBe($initialCount);
});

it('filters by specific model type', function () {
    // Create Post
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    // Create another Post
    $post2 = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post 2',
        'body' => 'Test Body 2',
    ]);

    // Make all Post versions old
    $post->versions()->update(['created_at' => now()->subDays(35)]);
    $post2->versions()->update(['created_at' => now()->subDays(35)]);

    // Create a Template version
    $template = Template::create([
        'name' => 'Test Template',
        'content' => 'Test Content',
    ]);
    $template->versions()->update(['created_at' => now()->subDays(35)]);

    config(['rewind.pruning.keep_version_one' => false]);

    // Prune only Post model
    $result = $this->pruneService->prune([
        'days' => 30,
        'model' => Post::class,
    ]);

    // Should delete only Post versions, not Template versions
    expect($result->deletedByModel)->toHaveKey(Post::class)
        ->and($result->deletedByModel)->not->toHaveKey(Template::class)
        ->and($template->versions()->count())->toBe(1); // Template version still exists
});

it('handles models with no versions gracefully', function () {
    $result = $this->pruneService->prune(['days' => 30]);

    expect($result->totalExamined)->toBe(0)
        ->and($result->totalDeleted)->toBe(0);
});

it('processes versions per model instance independently', function () {
    // Create two posts
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

    // Create more versions for post1
    for ($i = 1; $i <= 9; $i++) {
        $post1->update(['title' => "Post 1 Version {$i}"]);
    }

    // Create fewer versions for post2
    for ($i = 1; $i <= 3; $i++) {
        $post2->update(['title' => "Post 2 Version {$i}"]);
    }

    expect($post1->versions()->count())->toBe(10)
        ->and($post2->versions()->count())->toBe(4);

    // Keep last 5 per model
    $result = $this->pruneService->prune(['keep' => 5]);

    // Post1: keep versions 1 (protected), 6-10 = 6 versions
    // Post2: keep all 4 versions (less than 5)
    expect($post1->versions()->count())->toBe(6)
        ->and($post2->versions()->count())->toBe(4);
});

it('never deletes all versions (safety mechanism)', function () {
    config(['rewind.pruning.keep_version_one' => false]);
    config(['rewind.pruning.keep_snapshots' => false]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make ALL versions old (would normally delete all)
    $post->versions()->update(['created_at' => now()->subDays(100)]);

    $result = $this->pruneService->prune(['days' => 30]);

    // Should keep newest version as safety mechanism
    expect($post->versions()->count())->toBe(1)
        ->and($post->versions()->first()->version)->toBe(6); // Newest version
});

it('handles edge case where no snapshots exist except v1', function () {
    config(['rewind.pruning.keep_snapshots' => true]);
    config(['rewind.pruning.keep_version_one' => false]);
    config(['rewind.snapshot_interval' => 100]); // Very high interval, so no snapshots created

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 10; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make older versions old
    $post->versions()
        ->where('version', '<=', 5)
        ->update(['created_at' => now()->subDays(100)]);

    // Keep last 5 versions
    $result = $this->pruneService->prune(['keep' => 5]);

    // With keep_snapshots=true and only v1 as snapshot, should keep v1 and forward
    // Can't delete v1 as it's needed to reconstruct versions 7-11
    expect($post->versions()->min('version'))->toBe(1);
});

it('intersection policy keeps versions if either policy says to keep', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Test Body',
    ]);

    for ($i = 1; $i <= 5; $i++) {
        $post->update(['title' => "Version {$i}"]);
    }

    // Make all versions very old
    $post->versions()->update(['created_at' => now()->subDays(100)]);

    // Age policy says delete (all old), but count policy says keep (5 < 10)
    $result = $this->pruneService->prune([
        'days' => 30,
        'keep' => 10,
    ]);

    // Should delete nothing (count policy protects them)
    expect($result->totalDeleted)->toBe(0)
        ->and($post->versions()->count())->toBe(6);
});

it('returns accurate statistics', function () {
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

    $result = $this->pruneService->prune(['days' => 30]);

    expect($result->totalExamined)->toBe(6)
        ->and($result->totalDeleted)->toBe(2)
        ->and($result->totalPreserved)->toBe(4)
        ->and($result->getModelsAffected())->toBe(1)
        ->and($result->getSummary())->toContain('Deleted 2 of 6 versions');
});
