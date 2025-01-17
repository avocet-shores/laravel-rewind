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

test('it triggers the queued listener if async_listener = true', function () {
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
