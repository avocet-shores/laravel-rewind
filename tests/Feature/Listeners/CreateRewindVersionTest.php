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
        ->never();

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
    $listener->handle(new RewindVersionCreating($model));
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
    $listener->handle(new RewindVersionCreating($model));

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
    $listener->handle(new RewindVersionCreating($model));

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
    $listener->handle(new RewindVersionCreating($model));

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
    $listener->handle(new RewindVersionCreating($model));
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
        $listener->handle(new RewindVersionCreating($model));
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
        $listener->handle(new RewindVersionCreating($model));
    } catch (LockTimeoutRewindException) {
        // Expected
    }

    Event::assertDispatched(RewindVersionLockTimeout::class);
});
