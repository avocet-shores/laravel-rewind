<?php

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

it('suppresses version creation for changes within the callback', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::withoutVersioning(function () use ($post) {
        $post->update(['title' => 'Silent Update']);
    });

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(1); // Only the create version
    expect($post->title)->toBe('Silent Update'); // But the model was updated
});

it('resumes versioning after the callback completes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::withoutVersioning(function () use ($post) {
        $post->update(['title' => 'Silent']);
    });

    $post->update(['title' => 'Tracked']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2); // Create + tracked update
});

it('resumes versioning even when the callback throws', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    try {
        Rewind::withoutVersioning(function () {
            throw new RuntimeException('Boom');
        });
    } catch (RuntimeException) {
        // Expected
    }

    $post->update(['title' => 'After Exception']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2); // Create + post-exception update
});

it('returns the callback return value', function () {
    $result = Rewind::withoutVersioning(function () {
        return 'hello';
    });

    expect($result)->toBe('hello');
});

it('works across multiple models', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);

    Rewind::withoutVersioning(function () use ($post1, $post2) {
        $post1->update(['title' => 'Silent 1']);
        $post2->update(['title' => 'Silent 2']);
    });

    $count1 = RewindVersion::where('model_id', $post1->getKey())
        ->where('model_type', $post1->getMorphClass())
        ->count();
    $count2 = RewindVersion::where('model_id', $post2->getKey())
        ->where('model_type', $post2->getMorphClass())
        ->count();

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);
});

it('supports nesting without premature re-enable', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::withoutVersioning(function () use ($post) {
        Rewind::withoutVersioning(function () use ($post) {
            $post->update(['title' => 'Inner']);
        });

        // Still inside outer — should still be suppressed
        $post->update(['title' => 'Outer']);
    });

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(1); // Only create
});

it('preserves correct old_values for backward traversal after unversioned changes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    // Unversioned change: title goes from "V1" to "Untracked"
    Rewind::withoutVersioning(function () use ($post) {
        $post->update(['title' => 'Untracked']);
    });

    // Versioned change: title goes from "Untracked" to "V2"
    // old_values should reference V1's state ("V1"), not the unversioned "Untracked"
    $post->update(['title' => 'V2']);

    // Rewind to v1 — backward traversal applies old_values from v2
    Rewind::goTo($post, 1);
    $post->refresh();

    expect($post->title)->toBe('V1');
});
