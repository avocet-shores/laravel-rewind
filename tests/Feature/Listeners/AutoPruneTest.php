<?php

use AvocetShores\LaravelRewind\Models\RewindVersion;
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
});

it('returns null from maxRewindVersions when property is not defined', function () {
    expect(Post::maxRewindVersions())->toBeNull();
});

it('does not auto-prune when max_versions is null', function () {
    config()->set('rewind.max_versions', null);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(10);
});

it('does not auto-prune within the buffer window', function () {
    config()->set('rewind.max_versions', 5);
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    // Create 8 total versions (max 5 + interval 3 = 8, so <= threshold)
    for ($i = 2; $i <= 8; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(8);
});

it('auto-prunes when buffer is exceeded', function () {
    config()->set('rewind.max_versions', 5);
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    // Create 9 total versions (max 5 + interval 3 = 8, so 9 > threshold triggers prune)
    for ($i = 2; $i <= 9; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(5);

    // The latest 5 versions should remain
    $versions = RewindVersion::orderBy('version')->pluck('version')->toArray();
    expect($versions)->toBe([5, 6, 7, 8, 9]);
});

it('ensures oldest remaining version is a snapshot after auto-prune', function () {
    config()->set('rewind.max_versions', 3);
    config()->set('rewind.snapshot_interval', 2);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    // Threshold is 3 + 2 = 5, so 6 versions triggers prune
    for ($i = 2; $i <= 6; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->is_snapshot)->toBeTrue();
    expect($oldest->new_values)->toHaveKey('title');
});
