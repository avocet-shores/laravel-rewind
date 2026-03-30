<?php

use AvocetShores\LaravelRewind\Events\RewindVersionCreating;
use AvocetShores\LaravelRewind\LaravelRewindServiceProvider;
use AvocetShores\LaravelRewind\Listeners\CreateRewindVersion;
use AvocetShores\LaravelRewind\Listeners\CreateRewindVersionQueued;
use AvocetShores\LaravelRewind\Tests\Models\Post;

test('it triggers the synchronous listener if listener_should_queue = false', function () {
    // Fake the config
    Config::set('rewind.listener_should_queue', false);

    // Reboot the service provider to re-bind the event listener
    Event::forget(RewindVersionCreating::class);
    $this->app->register(LaravelRewindServiceProvider::class, true);

    // Create a mock of the synchronous listener
    $syncListenerMock = Mockery::mock(CreateRewindVersion::class)
        ->shouldReceive('handle')
        ->once()            // We expect the listener to be triggered exactly once
        ->andReturnNull()
        ->getMock();

    // Bind our mock into the service container so that, when the event is fired,
    $this->app->instance(CreateRewindVersion::class, $syncListenerMock);

    // Dispatch the event
    Post::create([
        'user_id' => 1,
        'title' => 'Initial Title',
        'body' => 'This is the body content',
    ]);
});

test('it triggers the queued listener if listener_should_queue = true', function () {
    // Fake the config
    Config::set('rewind.listener_should_queue', true);

    // Reboot the service provider to re-bind the event listener
    Event::forget(RewindVersionCreating::class);
    $this->app->register(LaravelRewindServiceProvider::class, true);

    // Create a mock of the queued listener
    $queuedListenerMock = Mockery::mock(CreateRewindVersionQueued::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturnNull()
        ->getMock();

    // Bind into the service container
    $this->app->instance(CreateRewindVersionQueued::class, $queuedListenerMock);

    // Dispatch the event
    Post::create([
        'user_id' => 1,
        'title' => 'Initial Title',
        'body' => 'This is the body content',
    ]);
});

test('it reads queue properties from config', function () {
    $listener = app(CreateRewindVersionQueued::class);

    expect($listener->tries)->toBe(3);
    expect($listener->backoff)->toBe([2, 10, 30]);
    expect($listener->timeout)->toBe(60);
});

test('it respects custom queue config values', function () {
    config()->set('rewind.queue.tries', 5);
    config()->set('rewind.queue.backoff', [1, 5]);
    config()->set('rewind.queue.timeout', 120);

    $listener = new CreateRewindVersionQueued;

    expect($listener->tries)->toBe(5);
    expect($listener->backoff)->toBe([1, 5]);
    expect($listener->timeout)->toBe(120);
});
