<?php

use AvocetShores\LaravelRewind\Enums\VersionEventType;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);

    $this->post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    $this->post->update(['title' => 'Updated Title']);
    $this->post->update(['title' => 'Final Title']);
});

it('filters versions for a specific model with forModel scope', function () {
    $otherPost = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Other Post',
        'body' => 'Other Body',
    ]);

    $versions = RewindVersion::forModel($this->post)->get();

    expect($versions)->toHaveCount(3);
    expect($versions->every(fn ($v) => $v->model_id === $this->post->id))->toBeTrue();
});

it('filters versions by user with byUser scope', function () {
    $otherUser = User::create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
    ]);

    // Manually create a version with a different user
    RewindVersion::create([
        'model_type' => $this->post->getMorphClass(),
        'model_id' => $this->post->getKey(),
        'version' => 999,
        config('rewind.user_id_column') => $otherUser->id,
        'old_values' => [],
        'new_values' => ['title' => 'test'],
        'is_snapshot' => false,
    ]);

    $versions = RewindVersion::byUser($this->user->id)->get();

    expect($versions->every(fn ($v) => $v->{config('rewind.user_id_column')} === $this->user->id))->toBeTrue();
    expect($versions)->toHaveCount(3);
});

it('filters versions by event type with ofType scope', function () {
    $created = RewindVersion::ofType(VersionEventType::Created)->get();
    $updated = RewindVersion::ofType(VersionEventType::Updated)->get();

    expect($created)->toHaveCount(1);
    expect($updated)->toHaveCount(2);
});

it('filters versions between dates with betweenDates scope', function () {
    // Set known timestamps on existing versions
    RewindVersion::where('version', 1)->update(['created_at' => Carbon::parse('2025-01-01')]);
    RewindVersion::where('version', 2)->update(['created_at' => Carbon::parse('2025-06-15')]);
    RewindVersion::where('version', 3)->update(['created_at' => Carbon::parse('2025-12-31')]);

    $versions = RewindVersion::betweenDates(
        Carbon::parse('2025-06-01'),
        Carbon::parse('2025-07-01')
    )->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('filters versions between version numbers with betweenVersions scope', function () {
    $versions = RewindVersion::betweenVersions(1, 2)->get();

    expect($versions)->toHaveCount(2);
    expect($versions->pluck('version')->sort()->values()->all())->toBe([1, 2]);
});

it('handles betweenDates with swapped arguments', function () {
    RewindVersion::where('version', 1)->update(['created_at' => Carbon::parse('2025-01-01')]);
    RewindVersion::where('version', 2)->update(['created_at' => Carbon::parse('2025-06-15')]);
    RewindVersion::where('version', 3)->update(['created_at' => Carbon::parse('2025-12-31')]);

    // Pass $to before $from — scope should still return the correct result
    $versions = RewindVersion::betweenDates(
        Carbon::parse('2025-07-01'),
        Carbon::parse('2025-06-01')
    )->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});
