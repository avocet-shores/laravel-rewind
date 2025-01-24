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
