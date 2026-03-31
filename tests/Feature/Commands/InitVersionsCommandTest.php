<?php

use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostThatIsNotRewindable;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    test()->actingAs($this->user);
});

it('creates initial versions for all records without versions', function () {
    // Create posts without versioning so they have no version records
    Post::withoutEvents(function () {
        Post::create(['user_id' => 1, 'title' => 'Post 1', 'body' => 'Body 1']);
        Post::create(['user_id' => 1, 'title' => 'Post 2', 'body' => 'Body 2']);
    });

    expect(RewindVersion::count())->toBe(0);

    artisan('rewind:init', [
        'model' => Post::class,
        '--force' => true,
    ])->assertExitCode(0);

    expect(RewindVersion::count())->toBe(2);

    // All should be v1 snapshots
    RewindVersion::all()->each(function ($version) {
        expect($version->version)->toBe(1);
        expect($version->is_snapshot)->toBeTrue();
    });
});

it('skips records that already have versions', function () {
    // One post with versioning (has v1 automatically)
    $existing = Post::create(['user_id' => $this->user->id, 'title' => 'Existing', 'body' => 'Body']);

    // One post without versioning
    Post::withoutEvents(function () {
        Post::create(['user_id' => 1, 'title' => 'New', 'body' => 'Body']);
    });

    expect(RewindVersion::count())->toBe(1);

    artisan('rewind:init', [
        'model' => Post::class,
        '--force' => true,
    ])->assertExitCode(0);

    // Only 1 new version created (for the post without versions)
    expect(RewindVersion::count())->toBe(2);
});

it('shows count in pretend mode without creating versions', function () {
    Post::withoutEvents(function () {
        Post::create(['user_id' => 1, 'title' => 'Post 1', 'body' => 'Body 1']);
        Post::create(['user_id' => 1, 'title' => 'Post 2', 'body' => 'Body 2']);
    });

    artisan('rewind:init', [
        'model' => Post::class,
        '--pretend' => true,
    ])
        ->expectsOutputToContain('2 record(s)')
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(0);
});

it('rejects models that do not use the Rewindable trait', function () {
    artisan('rewind:init', [
        'model' => PostThatIsNotRewindable::class,
        '--force' => true,
    ])->assertExitCode(1);
});

it('rejects non-existent model classes', function () {
    artisan('rewind:init', [
        'model' => 'App\\Models\\NonExistent',
        '--force' => true,
    ])->assertExitCode(1);
});

it('respects chunk size option', function () {
    Post::withoutEvents(function () {
        for ($i = 0; $i < 5; $i++) {
            Post::create(['user_id' => 1, 'title' => "Post {$i}", 'body' => 'Body']);
        }
    });

    artisan('rewind:init', [
        'model' => Post::class,
        '--chunk' => 2,
        '--force' => true,
    ])->assertExitCode(0);

    expect(RewindVersion::count())->toBe(5);
});
