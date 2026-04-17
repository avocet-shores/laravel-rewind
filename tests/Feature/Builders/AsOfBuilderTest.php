<?php

use AvocetShores\LaravelRewind\Exceptions\AsOfBuilderUsageException;
use AvocetShores\LaravelRewind\Exceptions\ReconstructedModelIsReadOnlyException;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\StateBuilder;
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

it('LIKE operator handles special regex characters in patterns', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Price: $10.00', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Price: $20.00', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'No price here', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', 'like', 'Price: $%')->get();

    expect($results)->toHaveCount(2);
});

it('LIKE operator supports underscore as single-character wildcard', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Cat', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Car', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Card', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', 'like', 'Ca_')->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('title')->sort()->values()->all())->toBe(['Car', 'Cat']);
});

it('includes restored soft-deleted models at the timestamp', function () {
    $template = Template::create(['name' => 'Template 1', 'content' => 'Content']);

    // Backdate create version
    RewindVersion::where('model_id', $template->getKey())
        ->where('model_type', $template->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(6)]);

    // Soft delete the template
    $template->delete();
    RewindVersion::where('model_id', $template->getKey())
        ->where('model_type', $template->getMorphClass())
        ->where('version', 2)
        ->update(['created_at' => now()->subHours(4)]);

    // Restore the template
    $template->restore();
    RewindVersion::where('model_id', $template->getKey())
        ->where('model_type', $template->getMorphClass())
        ->where('version', 3)
        ->update(['created_at' => now()->subHours(2)]);

    // Query after restore — model should be included
    $results = Template::asOf(now()->subHour())->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Template 1');

    // Query between delete and restore — model should be excluded
    $results = Template::asOf(now()->subHours(3))->get();
    expect($results)->toHaveCount(0);
});

it('applies multiple where clauses as AND logic', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Alpha', 'body' => 'match']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Beta', 'body' => 'match']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Alpha', 'body' => 'no-match']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))
        ->where('title', 'Alpha')
        ->where('body', 'match')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Alpha');
    expect($results->first()->body)->toBe('match');
});

it('handles null attribute comparisons', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Has Title', 'body' => null]);
    Post::create(['user_id' => $this->user->id, 'title' => 'Also Has Title', 'body' => 'Has Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('body', null)->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Has Title');
});

it('reconstructs correctly after versions have been pruned', function () {
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(10)]);

    $post->update(['title' => 'V2']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 2)
        ->update(['created_at' => now()->subHours(9)]);

    $post->update(['title' => 'V3']); // snapshot at v3
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 3)
        ->update(['created_at' => now()->subHours(8)]);

    $post->update(['title' => 'V4']);
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->where('version', 4)
        ->update(['created_at' => now()->subHours(7)]);

    // Simulate pruning v1 and v2 (keeping v3 snapshot onwards)
    RewindVersion::where('model_id', $post->getKey())
        ->where('model_type', $post->getMorphClass())
        ->whereIn('version', [1, 2])
        ->delete();

    // Query at v4's time — reconstruction should work from v3 snapshot
    $results = Post::asOf(now()->subHours(7))->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('V4');

    // Query at v3's time — snapshot itself
    $results = Post::asOf(now()->subHours(8))->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('V3');
});

it('returns first as null when no models match', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post', 'body' => 'Body']);

    $result = Post::asOf(now()->subYear())->first();

    expect($result)->toBeNull();
});

it('count ignores limit', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 3', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $count = Post::asOf(now()->subHours(2))->limit(1)->count();

    expect($count)->toBe(3);
});

it('first() does not permanently mutate the builder limit', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 1', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 2', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Post 3', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $builder = Post::asOf(now()->subHours(2));

    // Call first() — should not permanently set limit to 1
    $first = $builder->first();
    expect($first)->not->toBeNull();

    // Subsequent get() should still return all models
    $all = $builder->get();
    expect($all)->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Issue 1 — pre-asOf query constraints
|--------------------------------------------------------------------------
*/

it('throws when base query has a where clause before asOf', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'A', 'body' => 'b']);

    expect(fn () => Post::where('user_id', $this->user->id)->asOf(now()))
        ->toThrow(AsOfBuilderUsageException::class);
});

it('throws when base query has an orderBy before asOf', function () {
    expect(fn () => Post::orderBy('id')->asOf(now()))
        ->toThrow(AsOfBuilderUsageException::class);
});

it('throws when base query has a limit before asOf', function () {
    expect(fn () => Post::limit(5)->asOf(now()))
        ->toThrow(AsOfBuilderUsageException::class);
});

it('accepts an explicit empty query builder', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Empty', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::query()->asOf(now()->subHours(2))->get();

    expect($results)->toHaveCount(1);
});

it('allows where to be chained after asOf', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Keep', 'body' => 'Body']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Drop', 'body' => 'Body']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->where('title', 'Keep')->get();

    expect($results)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Issue 4 — reconstructed models are read-only
|--------------------------------------------------------------------------
*/

it('throws when save() is called on a reconstructed model', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $reconstructed = Post::asOf(now()->subHours(2))->first();
    $reconstructed->title = 'Evil';

    expect(fn () => $reconstructed->save())
        ->toThrow(ReconstructedModelIsReadOnlyException::class);
});

it('throws when update() is called on a reconstructed model', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $reconstructed = Post::asOf(now()->subHours(2))->first();

    expect(fn () => $reconstructed->update(['title' => 'Evil']))
        ->toThrow(ReconstructedModelIsReadOnlyException::class);
});

it('throws when delete() is called on a reconstructed model', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $reconstructed = Post::asOf(now()->subHours(2))->first();

    expect(fn () => $reconstructed->delete())
        ->toThrow(ReconstructedModelIsReadOnlyException::class);
});

it('allows replicate()->save() to persist a reconstructed model as a new row', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $reconstructed = Post::asOf(now()->subHours(2))->first();
    $clone = $reconstructed->replicate();
    $clone->save();

    expect($clone->exists)->toBeTrue();
    expect($clone->getKey())->not->toBe($post->getKey());
    expect($clone->isRewindReconstruction())->toBeFalse();
    expect(Post::count())->toBe(2);
});

it('reports isRewindReconstruction true on reconstructed models and false on fresh reads', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Original', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $reconstructed = Post::asOf(now()->subHours(2))->first();
    $live = Post::find($post->getKey());

    expect($reconstructed->isRewindReconstruction())->toBeTrue();
    expect($live->isRewindReconstruction())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Issue 2 — expanded where API (common subset)
|--------------------------------------------------------------------------
*/

it('filters with whereIn', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'A', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'B', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'C', 'body' => 'b']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->whereIn('title', ['A', 'C'])->get();

    expect($results->pluck('title')->sort()->values()->all())->toBe(['A', 'C']);
});

it('filters with whereNotIn', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'A', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'B', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'C', 'body' => 'b']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $results = Post::asOf(now()->subHours(2))->whereNotIn('title', ['A', 'C'])->get();

    expect($results->pluck('title')->all())->toBe(['B']);
});

it('filters with whereNull and whereNotNull', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'With body', 'body' => 'filled']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Without body', 'body' => null]);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $nulls = Post::asOf(now()->subHours(2))->whereNull('body')->get();
    $notNulls = Post::asOf(now()->subHours(2))->whereNotNull('body')->get();

    expect($nulls->pluck('title')->all())->toBe(['Without body']);
    expect($notNulls->pluck('title')->all())->toBe(['With body']);
});

it('filters with whereBetween and whereNotBetween', function () {
    Post::create(['user_id' => 10, 'title' => 'A', 'body' => 'b']);
    Post::create(['user_id' => 20, 'title' => 'B', 'body' => 'b']);
    Post::create(['user_id' => 30, 'title' => 'C', 'body' => 'b']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $between = Post::asOf(now()->subHours(2))->whereBetween('user_id', [15, 25])->get();
    $notBetween = Post::asOf(now()->subHours(2))->whereNotBetween('user_id', [15, 25])->get();

    expect($between->pluck('title')->all())->toBe(['B']);
    expect($notBetween->pluck('title')->sort()->values()->all())->toBe(['A', 'C']);
});

it('whereBetween requires exactly two values', function () {
    expect(fn () => Post::asOf(now())->whereBetween('user_id', [1]))
        ->toThrow(AsOfBuilderUsageException::class);
});

it('throws on unsupported where operator', function () {
    expect(fn () => Post::asOf(now())->where('title', '!~', 'x'))
        ->toThrow(AsOfBuilderUsageException::class);
});

/*
|--------------------------------------------------------------------------
| Issue 5 — memoisation of reconstruction
|--------------------------------------------------------------------------
*/

it('memoises reconstruction across count() and get()', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Memo', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $spy = new class(app(\AvocetShores\LaravelRewind\Services\ApproachEngine::class)) extends StateBuilder
    {
        public int $calls = 0;

        public function reconstructMultipleModelsAtVersions(array $modelVersionMap, string $modelType): array
        {
            $this->calls++;

            return parent::reconstructMultipleModelsAtVersions($modelVersionMap, $modelType);
        }
    };
    app()->instance(StateBuilder::class, $spy);

    $builder = Post::asOf(now()->subHours(2));
    $builder->count();
    $builder->get();
    $builder->first();

    expect($spy->calls)->toBe(1);
});

it('invalidates reconstruction cache when a where is added', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'Memo', 'body' => 'Body']);
    RewindVersion::where('model_id', $post->getKey())
        ->update(['created_at' => now()->subHours(3)]);

    $spy = new class(app(\AvocetShores\LaravelRewind\Services\ApproachEngine::class)) extends StateBuilder
    {
        public int $calls = 0;

        public function reconstructMultipleModelsAtVersions(array $modelVersionMap, string $modelType): array
        {
            $this->calls++;

            return parent::reconstructMultipleModelsAtVersions($modelVersionMap, $modelType);
        }
    };
    app()->instance(StateBuilder::class, $spy);

    $builder = Post::asOf(now()->subHours(2));
    $builder->count();
    $builder->where('title', 'Memo');
    $builder->get();

    expect($spy->calls)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Issue 3 — chunk() and cursor()
|--------------------------------------------------------------------------
*/

it('iterates all matches across chunks', function () {
    for ($i = 1; $i <= 25; $i++) {
        Post::create(['user_id' => $this->user->id, 'title' => "P{$i}", 'body' => 'Body']);
    }
    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $seen = 0;
    $chunks = 0;
    Post::asOf(now()->subHours(2))->chunk(10, function ($slice) use (&$seen, &$chunks) {
        $chunks++;
        $seen += $slice->count();
    });

    expect($seen)->toBe(25);
    expect($chunks)->toBe(3);
});

it('chunk respects where filters and still iterates the whole set', function () {
    Post::create(['user_id' => $this->user->id, 'title' => 'Match', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Skip', 'body' => 'b']);
    Post::create(['user_id' => $this->user->id, 'title' => 'Match', 'body' => 'b']);

    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $seen = collect();
    Post::asOf(now()->subHours(2))
        ->where('title', 'Match')
        ->chunk(10, function ($slice) use ($seen) {
            foreach ($slice as $m) {
                $seen->push($m->title);
            }
        });

    expect($seen->all())->toBe(['Match', 'Match']);
});

it('chunk halts when callback returns false', function () {
    for ($i = 1; $i <= 25; $i++) {
        Post::create(['user_id' => $this->user->id, 'title' => "P{$i}", 'body' => 'Body']);
    }
    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $chunks = 0;
    $result = Post::asOf(now()->subHours(2))->chunk(10, function () use (&$chunks) {
        $chunks++;

        return false;
    });

    expect($result)->toBeFalse();
    expect($chunks)->toBe(1);
});

it('chunk throws when orderBy is set', function () {
    expect(fn () => Post::asOf(now())->orderBy('title')->chunk(10, fn () => null))
        ->toThrow(AsOfBuilderUsageException::class);
});

it('cursor yields all matches and respects limit', function () {
    for ($i = 1; $i <= 10; $i++) {
        Post::create(['user_id' => $this->user->id, 'title' => "P{$i}", 'body' => 'Body']);
    }
    $post = Post::first();
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('version', 1)
        ->update(['created_at' => now()->subHours(3)]);

    $yielded = [];
    foreach (Post::asOf(now()->subHours(2))->limit(3)->cursor() as $model) {
        $yielded[] = $model->title;
    }

    expect($yielded)->toHaveCount(3);
});

it('cursor throws when orderBy is set', function () {
    $builder = Post::asOf(now())->orderBy('title');

    expect(function () use ($builder) {
        foreach ($builder->cursor() as $_) {
            // noop
        }
    })->toThrow(AsOfBuilderUsageException::class);
});
