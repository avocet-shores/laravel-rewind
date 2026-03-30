<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\VersionPruner;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);
    $this->pruner = app(VersionPruner::class);
});

it('prunes versions for a model keeping the specified max', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 12; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(12);

    $deleted = $this->pruner->pruneForModel($post, 5);

    expect($deleted)->toBe(7);
    expect(RewindVersion::count())->toBe(5);
});

it('converts the new oldest version to a snapshot after pruning', function () {
    config()->set('rewind.snapshot_interval', 10);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 15; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Before pruning: snapshots at versions 1 and 10
    expect(RewindVersion::where('version', 1)->first()->is_snapshot)->toBeTrue();
    expect(RewindVersion::where('version', 10)->first()->is_snapshot)->toBeTrue();

    // Prune keeping 5 (versions 11-15 remain)
    $this->pruner->pruneForModel($post, 5);

    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->version)->toBe(11);
    expect($oldest->is_snapshot)->toBeTrue();

    // The snapshot should contain the full state
    expect($oldest->new_values)->toHaveKey('title');
    expect($oldest->new_values['title'])->toBe('v11');
});

it('does not convert if new oldest is already a snapshot', function () {
    config()->set('rewind.snapshot_interval', 5);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Snapshot at version 5
    expect(RewindVersion::where('version', 5)->first()->is_snapshot)->toBeTrue();

    // Prune keeping 6 (versions 5-10 remain, oldest is already a snapshot)
    $this->pruner->pruneForModel($post, 6);

    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->version)->toBe(5);
    expect($oldest->is_snapshot)->toBeTrue();
});

it('returns zero when under the cap', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    $post->update(['title' => 'v2']);

    $deleted = $this->pruner->pruneForModel($post, 10);
    expect($deleted)->toBe(0);
    expect(RewindVersion::count())->toBe(2);
});

it('preserves navigability after pruning', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 12; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $this->pruner->pruneForModel($post, 5);

    // Remaining versions are 8-12
    $remaining = RewindVersion::orderBy('version')->pluck('version')->toArray();
    expect($remaining)->toBe([8, 9, 10, 11, 12]);

    // Should be able to goTo any remaining version
    foreach ($remaining as $version) {
        Rewind::goTo($post->fresh(), $version);
        expect($post->fresh()->title)->toBe("v{$version}");
    }
});

it('prunes across multiple model instances independently', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'p1v1', 'body' => 'body']);
    for ($i = 2; $i <= 8; $i++) {
        $post1->update(['title' => "p1v{$i}"]);
    }

    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'p2v1', 'body' => 'body']);
    for ($i = 2; $i <= 5; $i++) {
        $post2->update(['title' => "p2v{$i}"]);
    }

    $result = $this->pruner->prune(keepCount: 3);

    // post1: 8 versions -> 3 kept = 5 pruned
    // post2: 5 versions -> 3 kept = 2 pruned
    expect($result->totalDeleted)->toBe(7);
    expect(RewindVersion::where('model_id', $post1->id)->count())->toBe(3);
    expect(RewindVersion::where('model_id', $post2->id)->count())->toBe(3);
});

it('supports pretend mode in the service', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $result = $this->pruner->prune(keepCount: 3, pretend: true);

    expect($result->totalDeleted)->toBe(7);
    // Nothing actually deleted
    expect(RewindVersion::count())->toBe(10);
});

it('prunes by age correctly', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 8; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Backdate first 4 versions
    RewindVersion::where('model_id', $post->id)
        ->where('version', '<=', 4)
        ->update(['created_at' => now()->subDays(45)]);

    $result = $this->pruner->prune(olderThanDays: 30);

    expect($result->totalDeleted)->toBe(4);
    expect(RewindVersion::count())->toBe(4);
});
