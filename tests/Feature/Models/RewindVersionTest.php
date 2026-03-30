<?php

use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\User;

beforeEach(function () {
    // Create a user
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    // Set the user as the currently authenticated user
    test()->actingAs($this->user);
});

it('preserves string user_id when user_id_cast is set to string', function () {
    config()->set('rewind.user_id_cast', 'string');

    $uuidUserId = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Manually create a version with a UUID user_id to verify the cast preserves it
    $version = RewindVersion::create([
        'model_type' => $post->getMorphClass(),
        'model_id' => $post->getKey(),
        'version' => 999,
        config('rewind.user_id_column') => $uuidUserId,
        'old_values' => [],
        'new_values' => ['title' => 'test'],
        'is_snapshot' => false,
    ]);

    $version->refresh();

    // With integer cast, this UUID would be truncated to 0 or 9.
    // With string cast, the full UUID is preserved.
    expect($version->{config('rewind.user_id_column')})->toBe($uuidUserId);
});

it('returns the user attributed to the version', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Act: Update the post
    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    // Assert
    $version = RewindVersion::first();
    $user = $version->user()->first();
    expect($user->id)->toBe($this->user->id);
});
