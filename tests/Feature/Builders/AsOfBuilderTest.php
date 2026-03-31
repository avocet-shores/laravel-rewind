<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\Template;
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

it('reconstructs multiple models at a given timestamp', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 1 V1', 'body' => 'Body 1']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Post 2 V1', 'body' => 'Body 2']);

    // Backdate v1 versions
    RewindVersion::where('model_type', $post1->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    // Update both posts
    $post1->update(['title' => 'Post 1 V2']);
    $post2->update(['title' => 'Post 2 V2']);

    // Query at a time when only v1 existed
    $results = Post::asOf(now()->subHours(2))->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('title')->sort()->values()->all())->toBe(['Post 1 V1', 'Post 2 V1']);
});

it('excludes models that did not exist at the timestamp', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Old Post', 'body' => 'Body']);

    // Backdate post1's version
    RewindVersion::where('model_id', $post1->getKey())
        ->where('model_type', $post1->getMorphClass())
        ->update(['created_at' => now()->subHours(3)]);

    // Create a second post "now" (after the query timestamp)
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'New Post', 'body' => 'Body']);

    $results = Post::asOf(now()->subHours(2))->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Old Post');
});

it('filters reconstructed models with where clauses', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Alpha', 'body' => 'Body 1']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Beta', 'body' => 'Body 2']);

    // Backdate versions
    RewindVersion::where('model_type', $post1->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', 'Alpha')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Alpha');
});

it('supports comparison operators in where clauses', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'AAA', 'body' => 'Body']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'ZZZ', 'body' => 'Body']);

    RewindVersion::where('model_type', $post1->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', '!=', 'AAA')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('ZZZ');
});

it('applies where to reconstructed state, not current state', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);

    // Backdate v1
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    // Update the title (current state is now 'Updated')
    $post->update(['title' => 'Updated']);

    // The where should match the reconstructed state ('Original'), not current ('Updated')
    $results = Post::asOf(now()->subHours(2))->where('title', 'Original')->get();
    expect($results)->toHaveCount(1);

    $results = Post::asOf(now()->subHours(2))->where('title', 'Updated')->get();
    expect($results)->toHaveCount(0);
});

it('excludes soft-deleted models at the timestamp', function () {
    $template = Template::create(['name' => 'Template 1', 'content' => 'Content']);

    // Backdate create version
    RewindVersion::where('model_id', $template->getKey())
        ->where('model_type', $template->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(5)]);

    // Soft delete the template
    $template->delete();

    // Backdate the delete version
    RewindVersion::where('model_id', $template->getKey())
        ->where('model_type', $template->getMorphClass())
        ->where('version', 2)
        ->update(['created_at' => now()->subHours(3)]);

    // At the time of the query, the model was deleted
    $results = Template::asOf(now()->subHours(2))->get();
    expect($results)->toHaveCount(0);

    // Before deletion, the model existed
    $results = Template::asOf(now()->subHours(4))->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Template 1');
});

it('supports orderBy on reconstructed attributes', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Banana', 'body' => 'Body']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Apple', 'body' => 'Body']);
    $post3 = Post::create(['user_id' => $this->user->id, 'title' => 'Cherry', 'body' => 'Body']);

    RewindVersion::where('model_type', $post1->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->orderBy('title')->get();

    expect($results->pluck('title')->all())->toBe(['Apple', 'Banana', 'Cherry']);
});

it('supports orderBy desc', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'Banana', 'body' => 'Body']);
    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'Apple', 'body' => 'Body']);

    RewindVersion::where('model_type', $post1->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->orderBy('title', 'desc')->get();

    expect($results->pluck('title')->all())->toBe(['Banana', 'Apple']);
});

it('supports limit', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 3', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->limit(2)->get();

    expect($results)->toHaveCount(2);
});

it('supports first()', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $result = Post::asOf(now()->subHours(2))->first();

    expect($result)->not->toBeNull();
    expect($result)->toBeInstanceOf(Post::class);
});

it('supports count()', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 3', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $count = Post::asOf(now()->subHours(2))->count();

    expect($count)->toBe(3);
});

it('returns empty collection when no models exist at timestamp', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);

    $results = Post::asOf(now()->subYear())->get();

    expect($results)->toHaveCount(0);
});

it('works across snapshot boundaries', function () {
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(6)]);

    $post->update(['title' => 'V2']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 2)
        ->update(['created_at' => now()->subHours(5)]);

    $post->update(['title' => 'V3']); // snapshot
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 3)
        ->update(['created_at' => now()->subHours(4)]);

    $post->update(['title' => 'V4']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 4)
        ->update(['created_at' => now()->subHours(3)]);

    // Query at v2's time — crosses snapshot boundary
    $results = Post::asOf(now()->subHours(5))->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('V2');
});

it('works via the Rewind facade modelsAsOf method', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Facade Test', 'body' => 'Body']);

    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->update(['created_at' => now()->subHours(3)]);

    $results = Rewind::modelsAsOf(Post::class, now()->subHours(2))->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Facade Test');
});

it('hydrates models with their primary key', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Key Test', 'body' => 'Body']);
    $originalId = $post->id;

    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->update(['created_at' => now()->subHours(3)]);

    $result = Post::asOf(now()->subHours(2))->first();

    expect($result->id)->toBe($originalId);
    expect($result->exists)->toBeTrue();
});

it('supports chaining where with orderBy and limit', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Alpha', 'body' => 'match']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Beta', 'body' => 'match']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Gamma', 'body' => 'no-match']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Delta', 'body' => 'match']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))
        ->where('body', 'match')
        ->orderBy('title')
        ->limit(2)
        ->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('title')->all())->toBe(['Alpha', 'Beta']);
});

it('supports LIKE operator in where clause', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Hello World', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Goodbye World', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Hello There', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', 'like', 'Hello%')->get();

    expect($results)->toHaveCount(2);
});
