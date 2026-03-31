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

it('groups multiple model changes under a shared batch_uuid', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body 1']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body 2']);

    $batchUuid = Rewind::batch(function () use ($post1, $post2) {
        $post1->update(['title' => 'Updated Post 1']);
        $post2->update(['title' => 'Updated Post 2']);
    });

    // The update versions (v2 for each post) should share the batch UUID
    $batchVersions = RewindVersion::where('batch_uuid', $batchUuid)->get();
    expect($batchVersions)->toHaveCount(2);
    expect($batchVersions->pluck('batch_uuid')->unique()->first())->toBe($batchUuid);
});

it('returns the batch uuid from the batch method', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);

    $batchUuid = Rewind::batch(function () use ($post) {
        $post->update(['title' => 'Updated']);
    });

    expect($batchUuid)->toBeString();
    expect(strlen($batchUuid))->toBe(36); // UUID format
});

it('clears batch uuid after callback completes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);

    Rewind::batch(function () use ($post) {
        $post->update(['title' => 'Batched Update']);
    });

    // Version created outside the batch should have no batch_uuid
    $post->update(['title' => 'Non-Batched Update']);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->batch_uuid)->toBeNull();
});

it('clears batch uuid even when callback throws', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);

    try {
        Rewind::batch(function () {
            throw new RuntimeException('Something went wrong');
        });
    } catch (RuntimeException) {
        // Expected
    }

    $post->update(['title' => 'After Exception']);

    $latestVersion = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->orderByDesc('version')
        ->first();

    expect($latestVersion->batch_uuid)->toBeNull();
});

it('can query versions by batch uuid using inBatch scope', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body 1']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body 2']);

    $batchUuid = Rewind::batch(function () use ($post1, $post2) {
        $post1->update(['title' => 'Batch Post 1']);
        $post2->update(['title' => 'Batch Post 2']);
    });

    $batchVersions = RewindVersion::inBatch($batchUuid)->get();

    expect($batchVersions)->toHaveCount(2);
    expect($batchVersions->every(fn ($v) => $v->batch_uuid === $batchUuid))->toBeTrue();
});

it('does not assign batch_uuid to versions created outside a batch', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);
    $post->update(['title' => 'Updated']);

    $versions = RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->get();

    expect($versions->every(fn ($v) => $v->batch_uuid === null))->toBeTrue();
});

it('preserves meta alongside batch_uuid', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);

    $batchUuid = Rewind::batch(function () use ($post) {
        Rewind::withMeta(['reason' => 'bulk update']);
        $post->update(['title' => 'Updated']);
    });

    $version = RewindVersion::where('batch_uuid', $batchUuid)->first();

    expect($version->meta)->toBe(['reason' => 'bulk update']);
    expect($version->batch_uuid)->toBe($batchUuid);
});

it('throws when nesting batch calls', function () {
    Rewind::batch(function () {
        Rewind::batch(function () {
            // This should throw
        });
    });
})->throws(LogicException::class);

it('assigns batch_uuid to creation versions within a batch', function () {
    $batchUuid = Rewind::batch(function () {
        Post::create(['user_id' => $this->user->id, 'title' => 'Batch Created', 'body' => 'Body']);
    });

    $batchVersions = RewindVersion::where('batch_uuid', $batchUuid)->get();
    expect($batchVersions)->toHaveCount(1);
    expect($batchVersions->first()->version)->toBe(1);
});
