<?php

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostWithConditionalVersioning;
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

it('skips versioning when shouldVersion returns false', function () {
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    // Only body changes — shouldVersion checks for title
    $post->update(['body' => 'Updated Body']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    // Only the create version should exist
    expect($versionCount)->toBe(1);
});

it('creates versions when shouldVersion returns true', function () {
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    $post->update(['title' => 'Updated Title']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2);
});

it('receives only trackable changed attributes', function () {
    // PostWithConditionalVersioning checks for 'title' key.
    // Updating both title and body should pass because title is present.
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    $post->update(['title' => 'New Title', 'body' => 'New Body']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2);
});

it('still versions on create regardless of shouldVersion', function () {
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(1);
});

it('does not skip restore even when shouldVersion would return false', function () {
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);
    $post->update(['title' => 'V2 Title']);

    // Restore to v1 — even though body change might not pass shouldVersion,
    // restore should always create a version
    Rewind::restore($post, 1);

    $post->refresh();
    expect($post->current_version)->toBe(3);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->event_type)->toBe(VersionEventType::Restored);
});

it('preserves correct old_values for backward traversal after skipped changes', function () {
    $post = PostWithConditionalVersioning::create([
        'user_id' => $this->user->id,
        'title' => 'V1 Title',
        'body' => 'V1 Body',
    ]);

    // body-only change — shouldVersion returns false (only tracks title changes)
    $post->update(['body' => 'Untracked Body']);

    // title change — shouldVersion returns true, creates v2
    // old_values for body should reference V1's "V1 Body", not "Untracked Body"
    $post->update(['title' => 'V2 Title']);

    // Rewind to v1 — backward traversal applies old_values from v2
    Rewind::goTo($post, 1);
    $post->refresh();

    expect($post->title)->toBe('V1 Title');
    expect($post->body)->toBe('V1 Body');
});

it('defaults to true when shouldVersion is not overridden', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    $post->update(['body' => 'Updated Body']);

    $versionCount = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBe(2);
});
