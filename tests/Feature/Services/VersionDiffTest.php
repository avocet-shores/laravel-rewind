<?php

use AvocetShores\LaravelRewind\Dto\VersionDiff;
use AvocetShores\LaravelRewind\Facades\Rewind;
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

it('returns a diff between two adjacent versions', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->update(['title' => 'Updated Title']);

    $diff = Rewind::diff($post, 1, 2);

    expect($diff)->toBeInstanceOf(VersionDiff::class);
    expect($diff->changed)->toHaveKey('title');
    expect($diff->changed['title'])->toBe(['old' => 'Original Title', 'new' => 'Updated Title']);
    expect($diff->isEmpty())->toBeFalse();
});

it('returns an empty diff when comparing a version to itself', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $diff = Rewind::diff($post, 1, 1);

    expect($diff->isEmpty())->toBeTrue();
});

it('returns a diff across multiple versions', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->update(['title' => 'Updated Title']);
    $post->update(['body' => 'Updated Body']);
    $post->update(['title' => 'Final Title']);

    // Diff from v1 to v4 should capture all changes
    $diff = Rewind::diff($post, 1, 4);

    expect($diff->changed)->toHaveKey('title');
    expect($diff->changed['title'])->toBe(['old' => 'Original Title', 'new' => 'Final Title']);
    expect($diff->changed)->toHaveKey('body');
    expect($diff->changed['body'])->toBe(['old' => 'Original Body', 'new' => 'Updated Body']);
});

it('works in reverse order (higher version to lower)', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->update(['title' => 'Updated Title']);

    $diff = Rewind::diff($post, 2, 1);

    expect($diff->changed)->toHaveKey('title');
    expect($diff->changed['title'])->toBe(['old' => 'Updated Title', 'new' => 'Original Title']);
});

it('detects only changed attributes, ignoring unchanged ones', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->update(['title' => 'Updated Title']);

    $diff = Rewind::diff($post, 1, 2);

    // body did not change between v1 and v2
    expect($diff->changed)->not->toHaveKey('body');
    expect($diff->changed)->toHaveKey('title');
});

// --- Unit tests for VersionDiff::fromAttributes ---

it('detects added attributes in VersionDiff::fromAttributes', function () {
    $diff = VersionDiff::fromAttributes(
        ['a' => 1],
        ['a' => 1, 'b' => 2],
    );

    expect($diff->added)->toBe(['b' => 2]);
    expect($diff->changed)->toBeEmpty();
    expect($diff->removed)->toBeEmpty();
});

it('detects removed attributes in VersionDiff::fromAttributes', function () {
    $diff = VersionDiff::fromAttributes(
        ['a' => 1, 'b' => 2],
        ['a' => 1],
    );

    expect($diff->removed)->toBe(['b' => 2]);
    expect($diff->changed)->toBeEmpty();
    expect($diff->added)->toBeEmpty();
});
