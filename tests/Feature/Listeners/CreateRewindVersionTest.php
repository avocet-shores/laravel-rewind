<?php

use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\Events\RewindVersionLockTimeout;
use AvocetShores\LaravelRewind\Exceptions\LockTimeoutRewindException;
use AvocetShores\LaravelRewind\Listeners\CreateRewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use Illuminate\Cache\PhpRedisLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function mockLockTimeout(): void
{
    $lock = Mockery::mock(PhpRedisLock::class);
    $lock->shouldReceive('block')
        ->once()
        ->andThrow(LockTimeoutException::class);

    $lock->shouldReceive('release')
        ->once();

    $cacheSpy = Cache::spy();
    $cacheSpy->shouldReceive('lock')
        ->once()
        ->andReturn($lock);
}

it('Logs error when unable to acquire a lock', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    mockLockTimeout();

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Failed to acquire lock')
                && $context['model_key'] !== null;
        });

    $listener = new CreateRewindVersion;
    $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));
});

it('does not dispatch event in default log mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'log');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    $listener = new CreateRewindVersion;
    $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));

    Event::assertNotDispatched(RewindVersionLockTimeout::class);
});

it('dispatches RewindVersionLockTimeout event in event mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'event');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    $listener = new CreateRewindVersion;
    $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));

    Event::assertDispatched(RewindVersionLockTimeout::class, function ($event) use ($model) {
        return $event->model->is($model)
            && $event->exception instanceof LockTimeoutException
            && is_array($event->changes);
    });
});

it('does not throw exception in event mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'event');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    $listener = new CreateRewindVersion;
    $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));

    // If we got here without exception, the test passes
    expect(true)->toBeTrue();
});

it('throws LockTimeoutRewindException in throw mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'throw');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    $listener = new CreateRewindVersion;
    $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));
})->throws(LockTimeoutRewindException::class);

it('wraps original LockTimeoutException as previous in throw mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'throw');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    try {
        $listener = new CreateRewindVersion;
        $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));
        $this->fail('Expected LockTimeoutRewindException was not thrown');
    } catch (LockTimeoutRewindException $e) {
        expect($e->getPrevious())->toBeInstanceOf(LockTimeoutException::class);
        expect($e->getMessage())->toContain('Lock timeout while creating RewindVersion');
    }
});

it('dispatches event before throwing in throw mode', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    config()->set('rewind.on_lock_timeout', 'throw');

    Event::fake([RewindVersionLockTimeout::class]);
    mockLockTimeout();

    Log::shouldReceive('error')->once();

    try {
        $listener = new CreateRewindVersion;
        $listener->handle(new RewindVersionCreating($model, ['title' => 'Post Title']));
    } catch (LockTimeoutRewindException) {
        // Expected
    }

    Event::assertDispatched(RewindVersionLockTimeout::class);
});

// --- Fix 1: Event captures changes at dispatch time for queued listener support ---

it('captures changes and wasRecentlyCreated in the RewindVersionCreating event', function () {
    Event::fake([RewindVersionCreating::class]);

    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    Event::assertDispatched(RewindVersionCreating::class, function (RewindVersionCreating $event) {
        // The event should carry the changes captured at dispatch time
        expect($event->changes)->toBeArray()->not->toBeEmpty();
        expect($event->wasRecentlyCreated)->toBeTrue();

        return true;
    });
});

it('captures changes for updates in the RewindVersionCreating event', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    // Use a fresh instance to clear wasRecentlyCreated (which persists across refresh)
    $model = Post::find($model->id);

    Event::fake([RewindVersionCreating::class]);

    $model->update(['title' => 'Updated Title']);

    Event::assertDispatched(RewindVersionCreating::class, function (RewindVersionCreating $event) {
        expect($event->changes)->toHaveKey('title');
        expect($event->wasRecentlyCreated)->toBeFalse();

        return true;
    });
});

it('creates a version when listener receives event with pre-captured changes', function () {
    // Simulate what happens when a queued listener deserializes the event:
    // the model is fresh from DB (no dirty/changes), but the event carries the captured state.
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    // Simulate a fresh model (as if deserialized from queue) by re-fetching from DB
    $freshModel = Post::find($model->id);

    // The event carries the changes that were captured at dispatch time
    $event = new RewindVersionCreating(
        model: $freshModel,
        changes: ['title' => 'Updated Title'],
        wasRecentlyCreated: false,
    );

    // Verify model has no transient state (simulating deserialization)
    expect($freshModel->getChanges())->toBeEmpty();
    expect($freshModel->getDirty())->toBeEmpty();

    // Simulate the model having been updated before the event was serialized
    $freshModel->title = 'Updated Title';
    $freshModel->saveQuietly();

    $listener = new CreateRewindVersion;
    $listener->handle($event);

    // A version should have been created despite the model originally having no dirty/changes
    $versionCount = $freshModel->versions()->count();
    expect($versionCount)->toBe(2); // v1 from create + v2 from our manual handle
});

it('creates a version after serialize/unserialize round-trip of the event', function () {
    // Regression test for the queued listener bug. SerializesModels re-fetches
    // the model from DB on deserialization, killing getChanges(), getDirty(),
    // and wasRecentlyCreated. The fix stores these on the event itself.

    $model = Post::create([
        'user_id' => 1,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Simulate an update
    $model->title = 'Updated Title';
    $model->saveQuietly();

    // Build the event as dispatchRewindEvent() does
    $event = new RewindVersionCreating(
        model: $model,
        changes: $model->getChanges(),
        wasRecentlyCreated: false,
    );

    // Serialize and deserialize — this is what the queue does
    $restored = unserialize(serialize($event));

    // The model's transient state is gone after deserialization...
    expect($restored->model->getChanges())->toBeEmpty()
        ->and($restored->model->getDirty())->toBeEmpty()
        ->and($restored->model->wasRecentlyCreated)->toBeFalse();

    // ...but the event's captured state survived
    expect($restored->changes)->toHaveKey('title')
        ->and($restored->changes['title'])->toBe('Updated Title');

    // The listener creates a version from the deserialized event
    $listener = new CreateRewindVersion;
    $listener->handle($restored);

    // v1 from the original create + v2 from our listener call
    expect($model->versions()->count())->toBe(2);
    $v2 = $model->versions()->where('version', 2)->first();
    expect($v2)->not->toBeNull();
    expect($v2->new_values)->toHaveKey('title');
});

it('proves model transient state is lost after SerializesModels round-trip', function () {
    // This test documents the core problem: SerializesModels causes the model to
    // be re-fetched from DB, wiping all in-memory change tracking. Any listener
    // that reads getChanges()/getDirty()/wasRecentlyCreated from the model after
    // deserialization will see empty state and skip version creation.
    //
    // This is the exact scenario that broke the queued listener before the fix.
    // The listener's guard clause was:
    //   if (empty($dirty) && !$model->wasRecentlyCreated && $model->exists) return;
    // After deserialization, $dirty is always empty and wasRecentlyCreated is always
    // false, so the listener would ALWAYS bail out for queued jobs.

    $model = Post::create([
        'user_id' => 1,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Before serialization, the model has transient state
    expect($model->wasRecentlyCreated)->toBeTrue();
    expect($model->getChanges())->not->toBeEmpty();

    // Build a minimal event with SerializesModels
    $event = new RewindVersionCreating(model: $model);

    // Round-trip through serialization (what the queue does)
    $restored = unserialize(serialize($event));

    // After deserialization, ALL transient model state is gone
    $dirty = $restored->model->getChanges() ?: $restored->model->getDirty();
    expect($dirty)->toBeEmpty();
    expect($restored->model->wasRecentlyCreated)->toBeFalse();

    // The old guard clause would bail out:
    $wouldBailOut = empty($dirty) && ! $restored->model->wasRecentlyCreated && $restored->model->exists;
    expect($wouldBailOut)->toBeTrue('A listener reading model state after deserialization would skip version creation');

    // But the fixed listener reads $event->changes instead of $model->getChanges().
    // When the event is dispatched with changes captured at dispatch time, the
    // listener will see the changes even after deserialization.
});

// --- Fix 2: Version creation is wrapped in a DB transaction ---

it('creates version and updates current_version atomically', function () {
    $model = Post::create([
        'user_id' => 1,
        'title' => 'Post Title',
        'body' => 'Post Body',
    ]);

    // After creation, both the version record and current_version should be consistent
    expect($model->current_version)->toBe(1);
    expect($model->versions()->count())->toBe(1);

    $model->update(['title' => 'Updated Title']);

    expect($model->current_version)->toBe(2);
    expect($model->versions()->count())->toBe(2);

    // Verify the version record and model are in sync
    $latestVersion = $model->versions()->max('version');
    expect($model->current_version)->toBe($latestVersion);
});
