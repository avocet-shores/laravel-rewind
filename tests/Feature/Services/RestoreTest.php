<?php

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostThatIsNotRewindable;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    test()->actingAs($this->user);
});

it('creates a new version with attributes from a previous version', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1 Title', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2 Title']);
    $post->update(['title' => 'V3 Title']);

    $newVersion = Rewind::restore($post, 1);

    expect($newVersion)->toBe(4);
    $post->refresh();
    expect($post->title)->toBe('V1 Title');
    expect($post->body)->toBe('V1 Body');
    expect($post->current_version)->toBe(4);
});

it('preserves the full version history after restore', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);
    $post->update(['title' => 'V3']);

    Rewind::restore($post, 1);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(4);
});

it('marks the restored version with event_type restored', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    Rewind::restore($post, 1);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->event_type)->toBe(VersionEventType::Restored);
});

it('stores restored_from_version in meta', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    Rewind::restore($post, 1);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->meta)->toHaveKey('restored_from_version', 1);
});

it('throws when target version does not exist', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::restore($post, 999);
})->throws(VersionDoesNotExistException::class);

it('throws when model is not rewindable', function () {
    $post = PostThatIsNotRewindable::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    Rewind::restore($post, 1);
})->throws(ModelNotRewindableException::class);

it('can continue editing normally after restore', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    Rewind::restore($post, 1);

    // v4 should be at v1's state; now update again
    $post->update(['title' => 'V5 Title']);

    $post->refresh();
    expect($post->title)->toBe('V5 Title');
    expect($post->current_version)->toBe(4);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();
    expect($versionCount)->toBe(4);
});

it('works correctly with snapshots', function () {
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);
    $post->update(['title' => 'V3']); // snapshot at v3
    $post->update(['title' => 'V4']);
    $post->update(['title' => 'V5']);

    Rewind::restore($post, 2);

    $post->refresh();
    expect($post->title)->toBe('V2');
});

it('merges user-provided meta with restored_from_version', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    Rewind::withMeta(['reason' => 'rollback requested']);
    Rewind::restore($post, 1);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->meta)->toHaveKey('restored_from_version', 1);
    expect($latestVersion->meta)->toHaveKey('reason', 'rollback requested');
});
