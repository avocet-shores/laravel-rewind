<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Order;
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

it('records state transitions on create', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $version = $order->versions()->where('version', 1)->first();

    expect($version->state_transitions)->toBe([
        'status' => ['from' => null, 'to' => 'pending'],
        'payment_status' => ['from' => null, 'to' => 'unpaid'],
    ]);
});

it('records state transitions on update', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped']);

    $version = $order->versions()->where('version', 2)->first();

    expect($version->state_transitions)->toBe([
        'status' => ['from' => 'pending', 'to' => 'shipped'],
    ]);
});

it('tracks multiple state fields changing simultaneously', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped', 'payment_status' => 'paid']);

    $version = $order->versions()->where('version', 2)->first();

    expect($version->state_transitions)->toBe([
        'status' => ['from' => 'pending', 'to' => 'shipped'],
        'payment_status' => ['from' => 'unpaid', 'to' => 'paid'],
    ]);
});

it('does not record state transitions for non-state fields', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['total' => 200]);

    $version = $order->versions()->where('version', 2)->first();

    expect($version->state_transitions)->toBeNull();
});

it('does not record state transitions for models without rewindStateFields', function () {
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Test Post',
        'body' => 'Body',
    ]);

    $version = $post->versions()->where('version', 1)->first();

    expect($version->state_transitions)->toBeNull();
});

it('queries with whereStateTransition for exact from and to', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'processing']);
    $order->update(['status' => 'shipped']);

    $versions = $order->versions()->whereStateTransition('status', 'pending', 'processing')->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('queries with whereStateTransition using wildcard from', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped']);

    // Wildcard from (null), specific to
    $versions = $order->versions()->whereStateTransition('status', null, 'shipped')->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('queries with whereStateBecame', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped']);
    $order->update(['status' => 'delivered']);

    $versions = $order->versions()->whereStateBecame('status', 'shipped')->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('queries with whereStateWas', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'processing']);
    $order->update(['status' => 'shipped']);

    // Both v2 and v3 transitioned FROM something, but only v2 was FROM 'pending'
    $versions = $order->versions()->whereStateWas('status', 'pending')->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('queries with whereStateChanged', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped']);
    $order->update(['total' => 200]); // no state change

    // v1 (create) and v2 (status change) have state transitions, v3 does not
    $versions = $order->versions()->whereStateChanged('status')->get();

    expect($versions)->toHaveCount(2);
});

it('composes state scopes with existing scopes', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'shipped']);
    $order->update(['status' => 'delivered']);

    $versions = $order->versions()
        ->whereStateBecame('status', 'shipped')
        ->byUser($this->user->id)
        ->get();

    expect($versions)->toHaveCount(1);
    expect($versions->first()->version)->toBe(2);
});

it('returns state history for a field', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'processing']);
    $order->update(['status' => 'shipped']);

    $history = $order->stateHistory('status');

    expect($history)->toHaveCount(3);
    expect($history[0]['from'])->toBeNull();
    expect($history[0]['to'])->toBe('pending');
    expect($history[0]['version'])->toBe(1);
    expect($history[1]['from'])->toBe('pending');
    expect($history[1]['to'])->toBe('processing');
    expect($history[1]['version'])->toBe(2);
    expect($history[2]['from'])->toBe('processing');
    expect($history[2]['to'])->toBe('shipped');
    expect($history[2]['version'])->toBe(3);
});

it('preserves user-provided meta alongside state transitions', function () {
    Rewind::withMeta(['reason' => 'Customer request']);

    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $version = $order->versions()->where('version', 1)->first();

    expect($version->meta)->toBe(['reason' => 'Customer request']);
    expect($version->state_transitions)->toBe([
        'status' => ['from' => null, 'to' => 'pending'],
        'payment_status' => ['from' => null, 'to' => 'unpaid'],
    ]);
});

it('handles amend mode with state transitions', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    Rewind::amendCurrentVersion(function () use ($order) {
        $order->update(['status' => 'processing']);
        $order->update(['status' => 'shipped']);
    });

    // Should still be only 1 version (the create), amended
    expect($order->versions()->count())->toBe(1);

    $version = $order->versions()->where('version', 1)->first();

    // The state transition should show from: null (original) to: shipped (final)
    expect($version->state_transitions['status']['from'])->toBeNull();
    expect($version->state_transitions['status']['to'])->toBe('shipped');
});

it('records state transitions correctly on snapshot versions', function () {
    config()->set('rewind.snapshot_interval', 3);

    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['status' => 'processing']);

    // v3 will be a snapshot (interval=3)
    $order->update(['status' => 'shipped']);

    $snapshotVersion = $order->versions()->where('version', 3)->first();

    expect($snapshotVersion->is_snapshot)->toBeTrue();
    expect($snapshotVersion->state_transitions)->toBe([
        'status' => ['from' => 'processing', 'to' => 'shipped'],
    ]);
});

it('works with batch versioning', function () {
    $order1 = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order2 = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 200,
    ]);

    $batchUuid = Rewind::batch(function () use ($order1, $order2) {
        $order1->update(['status' => 'shipped']);
        $order2->update(['status' => 'processing']);
    });

    $batchVersions = RewindVersion::where('batch_uuid', $batchUuid)->get();

    expect($batchVersions)->toHaveCount(2);

    $order1Version = $batchVersions->first(fn ($v) => $v->model_id === $order1->id);
    $order2Version = $batchVersions->first(fn ($v) => $v->model_id === $order2->id);

    expect($order1Version->state_transitions['status'])->toBe(['from' => 'pending', 'to' => 'shipped']);
    expect($order2Version->state_transitions['status'])->toBe(['from' => 'pending', 'to' => 'processing']);
});

it('returns empty collection from stateHistory for field with no transitions', function () {
    $order = Order::create([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 100,
    ]);

    $order->update(['total' => 200]);

    // payment_status only changed on create (v1)
    $history = $order->stateHistory('total');

    expect($history)->toHaveCount(0);
});

it('returns getRewindStateFields from the model', function () {
    $order = new Order;
    expect($order->getRewindStateFields())->toBe(['status', 'payment_status']);

    $post = new Post;
    expect($post->getRewindStateFields())->toBe([]);
});
