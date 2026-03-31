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

it('does not create a new version for changes within the callback', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['title' => 'Amended']);
    });

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(1); // Still only v1, no new version row
    expect($post->title)->toBe('Amended'); // But the model was updated in DB
});

it('amends the current version record with the changed attributes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Original Body']);
    $post->update(['title' => 'V2']);

    // v2 currently has old_values: {title: "V1"}, new_values: {title: "V2"}
    // body is not in either because it didn't change in v2

    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['body' => 'Amended Body']);
    });

    $v2 = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 2)
        ->first();

    // body should now be added to v2's old_values and new_values
    expect($v2->old_values)->toHaveKey('body', 'Original Body');
    expect($v2->new_values)->toHaveKey('body', 'Amended Body');
    // title should still be there
    expect($v2->old_values)->toHaveKey('title', 'V1');
    expect($v2->new_values)->toHaveKey('title', 'V2');
});

it('preserves backward traversal after amended changes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['title' => 'Amended']);
    });

    // Rewind to v1 — v1 should have the amended value since it was amended onto v1
    // Actually, v1 is a create snapshot. The amend updates v1's new_values.
    $attributes = Rewind::getVersionAttributes($post, 1);
    expect($attributes['title'])->toBe('Amended');
});

it('resumes normal versioning after the callback completes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['title' => 'Amended']);
    });

    $post->update(['title' => 'V2']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2); // v1 (amended) + v2
});

it('resumes normal versioning even when the callback throws', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    try {
        Rewind::amendCurrentVersion(function () {
            throw new RuntimeException('Boom');
        });
    } catch (RuntimeException) {
        // Expected
    }

    $post->update(['title' => 'After Exception']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2); // v1 + post-exception v2
});

it('returns the callback return value', function () {
    $result = Rewind::amendCurrentVersion(function () {
        return 'hello';
    });

    expect($result)->toBe('hello');
});

it('works across multiple models', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post1, $post2) {
        $post1->update(['title' => 'Amended 1']);
        $post2->update(['title' => 'Amended 2']);
    });

    // No new version rows created
    $count1 = RewindVersion::where('model_id', $post1->getKey())
        ->where('model_type', $post1->getMorphClass())
        ->count();
    $count2 = RewindVersion::where('model_id', $post2->getKey())
        ->where('model_type', $post2->getMorphClass())
        ->count();

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);

    // But versions are amended
    $v1_1 = $post1->versions()->first();
    expect($v1_1->new_values)->toHaveKey('title', 'Amended 1');

    $v1_2 = $post2->versions()->first();
    expect($v1_2->new_values)->toHaveKey('title', 'Amended 2');
});

it('supports nesting without premature re-enable', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post) {
        Rewind::amendCurrentVersion(function () use ($post) {
            $post->update(['title' => 'Inner']);
        });

        // Still inside outer — should still amend, not create new version
        $post->update(['title' => 'Outer']);
    });

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(1); // Only v1, amended twice
    expect($post->fresh()->title)->toBe('Outer');
});

it('correctly handles next versioned save after amend without drift detection', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    // Amend changes title on v1
    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['title' => 'Amended']);
    });

    // Normal versioned save — creates v2
    $post->update(['title' => 'V2']);

    // v2's old_values should reference the amended state
    $v2 = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 2)
        ->first();

    expect($v2->old_values)->toHaveKey('title', 'Amended');
    expect($v2->new_values)->toHaveKey('title', 'V2');
});

it('preserves full backward traversal through amended and normal versions', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2']);

    // Amend body on v2
    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['body' => 'Amended Body']);
    });

    // Create v3
    $post->update(['title' => 'V3']);

    // Rewind to v1
    Rewind::goTo($post, 1);
    $post->refresh();

    expect($post->title)->toBe('V1');
    expect($post->body)->toBe('V1 Body');
});

it('handles amend on v1 create snapshot correctly', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::amendCurrentVersion(function () use ($post) {
        $post->update(['title' => 'Amended V1']);
    });

    // v1 is a snapshot — new_values should include the amended title
    $v1 = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->first();

    expect($v1->new_values['title'])->toBe('Amended V1');
    expect($v1->is_snapshot)->toBeTrue();
});
