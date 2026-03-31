<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\CustomRewindVersion;
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

it('defaults to RewindVersion when version_model is not configured', function () {
    expect(RewindVersion::resolveVersionModelClass())->toBe(RewindVersion::class);
});

it('resolves a custom version model class from config', function () {
    config()->set('rewind.version_model', CustomRewindVersion::class);

    expect(RewindVersion::resolveVersionModelClass())->toBe(CustomRewindVersion::class);
});

it('rejects a non-subclass with InvalidArgumentException', function () {
    config()->set('rewind.version_model', \stdClass::class);

    RewindVersion::resolveVersionModelClass();
})->throws(\InvalidArgumentException::class);

it('creates version records using the custom model', function () {
    config()->set('rewind.version_model', CustomRewindVersion::class);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Title', 'body' => 'Body']);

    $version = $post->versions()->first();
    expect($version)->toBeInstanceOf(CustomRewindVersion::class);
    expect($version->is_custom)->toBeTrue();
});

it('returns custom model instances from the versions relationship', function () {
    config()->set('rewind.version_model', CustomRewindVersion::class);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Title', 'body' => 'Body']);
    $post->update(['title' => 'Updated']);

    $versions = $post->versions;
    expect($versions)->toHaveCount(2);
    expect($versions->first())->toBeInstanceOf(CustomRewindVersion::class);
});

it('works with state reconstruction using custom model', function () {
    config()->set('rewind.version_model', CustomRewindVersion::class);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);
    $post->update(['title' => 'V3']);

    Rewind::goTo($post, 1);
    $post->refresh();

    expect($post->title)->toBe('V1');
});

it('works with version pruning using custom model', function () {
    config()->set('rewind.version_model', CustomRewindVersion::class);
    config()->set('rewind.max_versions', 2);
    config()->set('rewind.snapshot_interval', 2);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    for ($i = 2; $i <= 6; $i++) {
        $post->update(['title' => "V{$i}"]);
    }

    // Auto-prune should have kicked in; verify we can still navigate
    $post->refresh();
    expect($post->current_version)->toBe(6);

    $versionCount = CustomRewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->count();

    expect($versionCount)->toBeLessThanOrEqual(4);
});

it('handles null version_model config gracefully', function () {
    config()->set('rewind.version_model', null);

    expect(RewindVersion::resolveVersionModelClass())->toBe(RewindVersion::class);
});
