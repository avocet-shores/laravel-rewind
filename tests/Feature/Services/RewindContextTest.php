<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Services\RewindContext;
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

it('stores metadata on a version when set via Rewind::withMeta()', function () {
    Rewind::withMeta(['reason' => 'Bulk update', 'ticket' => 'JIRA-123']);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    $version = $post->versions()->where('version', 1)->first();
    expect($version->meta)->toBe(['reason' => 'Bulk update', 'ticket' => 'JIRA-123']);
});

it('flushes metadata after version creation so it does not leak', function () {
    Rewind::withMeta(['reason' => 'First change']);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    // Second update without calling withMeta
    $post->update(['title' => 'Updated Title']);

    $v1 = $post->versions()->where('version', 1)->first();
    $v2 = $post->versions()->where('version', 2)->first();

    expect($v1->meta)->toBe(['reason' => 'First change']);
    expect($v2->meta)->toBeNull();
});

it('stores metadata on update versions', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    Rewind::withMeta(['reason' => 'Price adjustment']);
    $post->update(['title' => 'Updated Title']);

    $v2 = $post->versions()->where('version', 2)->first();
    expect($v2->meta)->toBe(['reason' => 'Price adjustment']);
});

it('sets and flushes metadata via RewindContext directly', function () {
    $context = app(RewindContext::class);

    $context->set(['key' => 'value']);
    expect($context->get())->toBe(['key' => 'value']);

    $flushed = $context->flush();
    expect($flushed)->toBe(['key' => 'value']);
    expect($context->get())->toBe([]);
});

it('is registered as a singleton', function () {
    $a = app(RewindContext::class);
    $b = app(RewindContext::class);

    expect($a)->toBe($b);
});
