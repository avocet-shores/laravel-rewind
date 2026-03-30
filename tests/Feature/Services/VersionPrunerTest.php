<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Services\StateBuilder;
use AvocetShores\LaravelRewind\Services\VersionPruner;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);
    $this->pruner = app(VersionPruner::class);
});

it('prunes versions for a model keeping the specified max', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 12; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(12);

    $deleted = $this->pruner->pruneForModel($post, 5);

    expect($deleted)->toBe(7);
    expect(RewindVersion::count())->toBe(5);
});

it('converts the new oldest version to a snapshot after pruning', function () {
    config()->set('rewind.snapshot_interval', 10);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 15; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Before pruning: snapshots at versions 1 and 10
    expect(RewindVersion::where('version', 1)->first()->is_snapshot)->toBeTrue();
    expect(RewindVersion::where('version', 10)->first()->is_snapshot)->toBeTrue();

    // Prune keeping 5 (versions 11-15 remain)
    $this->pruner->pruneForModel($post, 5);

    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->version)->toBe(11);
    expect($oldest->is_snapshot)->toBeTrue();

    // The snapshot should contain the full state
    expect($oldest->new_values)->toHaveKey('title');
    expect($oldest->new_values['title'])->toBe('v11');
});

it('does not convert if new oldest is already a snapshot', function () {
    config()->set('rewind.snapshot_interval', 5);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Snapshot at version 5
    expect(RewindVersion::where('version', 5)->first()->is_snapshot)->toBeTrue();

    // Prune keeping 6 (versions 5-10 remain, oldest is already a snapshot)
    $this->pruner->pruneForModel($post, 6);

    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->version)->toBe(5);
    expect($oldest->is_snapshot)->toBeTrue();
});

it('returns zero when under the cap', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    $post->update(['title' => 'v2']);

    $deleted = $this->pruner->pruneForModel($post, 10);
    expect($deleted)->toBe(0);
    expect(RewindVersion::count())->toBe(2);
});

it('returns zero when model has no versions at all', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);

    // Delete all versions to simulate a model with no version records
    RewindVersion::where('model_id', $post->id)->delete();

    // Non-pretend path
    $deleted = $this->pruner->pruneForModel($post, 5);
    expect($deleted)->toBe(0);

    // Pretend path
    $result = $this->pruner->prune(keepCount: 5, pretend: true);
    expect($result->totalDeleted)->toBe(0);
});

it('preserves navigability after pruning', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 12; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $this->pruner->pruneForModel($post, 5);

    // Remaining versions are 8-12
    $remaining = RewindVersion::orderBy('version')->pluck('version')->toArray();
    expect($remaining)->toBe([8, 9, 10, 11, 12]);

    // Should be able to goTo any remaining version
    foreach ($remaining as $version) {
        Rewind::goTo($post->fresh(), $version);
        expect($post->fresh()->title)->toBe("v{$version}");
    }
});

it('prunes across multiple model instances independently', function () {
    $post1 = Post::create(['user_id' => $this->user->id, 'title' => 'p1v1', 'body' => 'body']);
    for ($i = 2; $i <= 8; $i++) {
        $post1->update(['title' => "p1v{$i}"]);
    }

    $post2 = Post::create(['user_id' => $this->user->id, 'title' => 'p2v1', 'body' => 'body']);
    for ($i = 2; $i <= 5; $i++) {
        $post2->update(['title' => "p2v{$i}"]);
    }

    $result = $this->pruner->prune(keepCount: 3);

    // post1: 8 versions -> 3 kept = 5 pruned
    // post2: 5 versions -> 3 kept = 2 pruned
    expect($result->totalDeleted)->toBe(7);
    expect(RewindVersion::where('model_id', $post1->id)->count())->toBe(3);
    expect(RewindVersion::where('model_id', $post2->id)->count())->toBe(3);
});

it('supports pretend mode in the service', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $result = $this->pruner->prune(keepCount: 3, pretend: true);

    expect($result->totalDeleted)->toBe(7);
    // Nothing actually deleted
    expect(RewindVersion::count())->toBe(10);
});

it('produces correct snapshots after successive prune cycles remove earlier snapshots', function () {
    // Regression: when auto-prune fires after every version creation, each cycle's
    // transaction deletes old versions. On subsequent cycles, reconstructStateAtVersion
    // starts from version 0 with empty attributes. The approach engine may pick the
    // Direct path (from=0, target=N), which walks through version numbers that were
    // pruned in previous cycles.
    //
    // buildFromDiffs must handle these gaps without errors and produce correct state.
    // The fix: iterate actual version records in the collection, not sequential integers.
    config()->set('rewind.snapshot_interval', 100); // avoid interval snapshots

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'original body']);

    // Simulate auto-prune after each update (like the real auto-prune flow).
    // Each prune is a separate committed transaction, so deletions persist
    // and are visible to subsequent prune cycles.
    $maxVersions = 5;
    for ($i = 2; $i <= 8; $i++) {
        $post->update(['title' => "v{$i}"]);
        $this->pruner->pruneForModel($post, $maxVersions);
    }

    // After many prune cycles, only the last 5 versions should remain
    $remaining = RewindVersion::where('model_id', $post->id)
        ->orderBy('version')
        ->pluck('version')
        ->toArray();
    expect($remaining)->toBe([4, 5, 6, 7, 8]);

    // The oldest remaining version must be a valid snapshot with ALL attributes,
    // including 'body' which was set at creation time (v1, now pruned).
    // The snapshot chain must have preserved it through successive conversions.
    $oldest = RewindVersion::where('model_id', $post->id)->orderBy('version')->first();
    expect($oldest->is_snapshot)->toBeTrue();
    expect($oldest->new_values['title'])->toBe('v4');
    expect($oldest->new_values)->toHaveKey('body');
    expect($oldest->new_values['body'])->toBe('original body');

    // All remaining versions must be navigable with correct state
    foreach ($remaining as $version) {
        Rewind::goTo($post->fresh(), $version);
        $fresh = $post->fresh();
        expect($fresh->title)->toBe("v{$version}");
        expect($fresh->body)->toBe('original body');
    }
});

it('handles missing version records gracefully during state reconstruction', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 5; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Delete a middle version to create a gap
    RewindVersion::where('model_type', $post->getMorphClass())
        ->where('model_id', $post->id)
        ->where('version', 3)
        ->delete();

    // StateBuilder should still reconstruct, skipping the missing version
    $stateBuilder = app(StateBuilder::class);
    $state = $stateBuilder->reconstructStateAtVersion(
        $post->getMorphClass(),
        $post->id,
        5,
    );

    expect($state)->toBeArray();
    expect($state)->toHaveKey('title');
});

it('reconstructs state correctly when version records have gaps from pruning', function () {
    // Regression: buildFromDiffs previously iterated through sequential integers
    // (for $ver = fromVersion+1; $ver <= targetVersion; $ver++). After pruning,
    // the old versions are gone but the new oldest has been converted to a snapshot
    // (the pruner converts BEFORE deleting). This test simulates the real pruner
    // flow: convert first, then delete, then verify state is correct.
    config()->set('rewind.snapshot_interval', 100);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'original']);
    for ($i = 2; $i <= 8; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $stateBuilder = app(StateBuilder::class);

    // Simulate first prune cycle: convert v3 to snapshot BEFORE deleting v1,v2
    // (this is what the real pruner does inside its transaction)
    $stateAtV3 = $stateBuilder->reconstructStateAtVersion(
        $post->getMorphClass(), $post->id, 3,
    );
    $v3 = RewindVersion::where('model_id', $post->id)->where('version', 3)->first();
    $v3->new_values = $stateAtV3;
    $v3->is_snapshot = true;
    $v3->save();
    RewindVersion::where('model_id', $post->id)->where('version', '<=', 2)->delete();

    // Simulate second prune cycle: convert v5 BEFORE deleting v3,v4
    $stateAtV5 = $stateBuilder->reconstructStateAtVersion(
        $post->getMorphClass(), $post->id, 5,
    );
    $v5 = RewindVersion::where('model_id', $post->id)->where('version', 5)->first();
    $v5->new_values = $stateAtV5;
    $v5->is_snapshot = true;
    $v5->save();
    RewindVersion::where('model_id', $post->id)->where('version', '<=', 4)->delete();

    // Only v5-v8 remain, v5 is a snapshot
    $remaining = RewindVersion::where('model_id', $post->id)
        ->orderBy('version')->pluck('version')->toArray();
    expect($remaining)->toBe([5, 6, 7, 8]);

    // Reconstruct state at v8 — must include body from original creation,
    // preserved through the snapshot chain
    $state = $stateBuilder->reconstructStateAtVersion(
        $post->getMorphClass(), $post->id, 8,
    );

    expect($state)->toHaveKey('title');
    expect($state['title'])->toBe('v8');
    expect($state)->toHaveKey('body');
    expect($state['body'])->toBe('original');
});

it('returns zero when no versions are old enough to prune by age', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 5; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // All versions are recent, none older than 30 days
    $result = $this->pruner->prune(olderThanDays: 30);

    expect($result->totalDeleted)->toBe(0);
    expect(RewindVersion::count())->toBe(5);
});

it('prunes by age correctly', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 8; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Backdate first 4 versions
    RewindVersion::where('model_id', $post->id)
        ->where('version', '<=', 4)
        ->update(['created_at' => now()->subDays(45)]);

    $result = $this->pruner->prune(olderThanDays: 30);

    expect($result->totalDeleted)->toBe(4);
    expect(RewindVersion::count())->toBe(4);
});
