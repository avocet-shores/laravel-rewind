<?php

use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
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

it('returns attributes at the exact timestamp of a version', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    $v1Time = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->first()
        ->created_at;

    $attributes = Rewind::versionAt($post, $v1Time);

    expect($attributes['title'])->toBe('V1');
});

it('returns the most recent version before the given timestamp', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    // Backdate v1 to ensure clear separation
    RewindVersion::where('model_id', $post->getKey())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $post->update(['title' => 'V2']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('version', 2)
        ->update(['created_at' => now()->subHours(2)]);

    $post->update(['title' => 'V3']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('version', 3)
        ->update(['created_at' => now()->subHour()]);

    // Query for a time between v2 and v3
    $attributes = Rewind::versionAt($post, now()->subMinutes(90));

    expect($attributes['title'])->toBe('V2');
});

it('throws when no version exists before the timestamp', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    // Query for a time before the model was created
    Rewind::versionAt($post, now()->subYear());
})->throws(VersionDoesNotExistException::class);

it('works across snapshots and diffs', function () {
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())->where('version', 1)
        ->update(['created_at' => now()->subHours(5)]);

    $post->update(['title' => 'V2']);
    RewindVersion::where('model_id', $post->getKey())->where('version', 2)
        ->update(['created_at' => now()->subHours(4)]);

    $post->update(['title' => 'V3']); // snapshot
    RewindVersion::where('model_id', $post->getKey())->where('version', 3)
        ->update(['created_at' => now()->subHours(3)]);

    $post->update(['title' => 'V4']);
    RewindVersion::where('model_id', $post->getKey())->where('version', 4)
        ->update(['created_at' => now()->subHours(2)]);

    // Query for v2's time — crosses snapshot boundary
    $attributes = Rewind::versionAt($post, now()->subHours(4));

    expect($attributes['title'])->toBe('V2');
});

it('returns the highest version when multiple versions share the same timestamp', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);
    $post->update(['title' => 'V3']);

    // Force all versions to the same timestamp
    $sameTime = now()->subHour();
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->update(['created_at' => $sameTime]);

    $attributes = Rewind::versionAt($post, $sameTime);

    expect($attributes['title'])->toBe('V3');
});

it('returns the latest version when timestamp is in the future', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    $attributes = Rewind::versionAt($post, now()->addYear());

    expect($attributes['title'])->toBe('V2');
});
