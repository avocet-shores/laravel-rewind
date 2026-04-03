<?php

use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostThatIsNotRewindable;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
    test()->actingAs($this->user);
});

it('calls the callback for each version with reconstructed attributes', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1 Title', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2 Title']);
    $post->update(['title' => 'V3 Title']);

    $collected = [];
    Rewind::replay($post, 1, 3, function (RewindVersion $version, array $attributes) use (&$collected) {
        $collected[$version->version] = $attributes['title'];
    });

    expect($collected)->toBe([
        1 => 'V1 Title',
        2 => 'V2 Title',
        3 => 'V3 Title',
    ]);
});

it('provides RewindVersion instances with full metadata', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);

    $versions = [];
    Rewind::replay($post, 1, 2, function (RewindVersion $version, array $attributes) use (&$versions) {
        $versions[] = $version;
    });

    expect($versions)->toHaveCount(2);
    expect($versions[0])->toBeInstanceOf(RewindVersion::class);
    expect($versions[0]->version)->toBe(1);
    expect($versions[1]->version)->toBe(2);
});

it('collects callback return values into a Collection', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);
    $post->update(['title' => 'V2']);
    $post->update(['title' => 'V3']);

    $result = Rewind::replay($post, 1, 3, function (RewindVersion $version, array $attributes) {
        return 'v'.$version->version.':'.$attributes['title'];
    });

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result->all())->toBe([
        'v1:V1',
        'v2:V2',
        'v3:V3',
    ]);
});

it('handles single version replay where from equals to', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1 Title', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2 Title']);

    $callCount = 0;
    Rewind::replay($post, 2, 2, function (RewindVersion $version, array $attributes) use (&$callCount) {
        $callCount++;
        expect($version->version)->toBe(2);
        expect($attributes['title'])->toBe('V2 Title');
    });

    expect($callCount)->toBe(1);
});

it('supports reverse replay when from is greater than to', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1 Title', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2 Title']);
    $post->update(['title' => 'V3 Title']);

    $collected = [];
    Rewind::replay($post, 3, 1, function (RewindVersion $version, array $attributes) use (&$collected) {
        $collected[$version->version] = $attributes['title'];
    });

    expect($collected)->toBe([
        3 => 'V3 Title',
        2 => 'V2 Title',
        1 => 'V1 Title',
    ]);
});

it('throws when from version does not exist', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::replay($post, 999, 1, function () {});
})->throws(VersionDoesNotExistException::class);

it('throws when to version does not exist', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    Rewind::replay($post, 1, 999, function () {});
})->throws(VersionDoesNotExistException::class);

it('throws when model is not rewindable', function () {
    $post = PostThatIsNotRewindable::create([
        'user_id' => $this->user->id,
        'title' => 'Title',
        'body' => 'Body',
    ]);

    Rewind::replay($post, 1, 1, function () {});
})->throws(ModelNotRewindableException::class);

it('works correctly across snapshot boundaries', function () {
    config()->set('rewind.snapshot_interval', 3);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1', 'body' => 'Body']);

    for ($i = 2; $i <= 12; $i++) {
        $post->update(['title' => "V{$i}"]);
    }

    $collected = [];
    Rewind::replay($post, 1, 12, function (RewindVersion $version, array $attributes) use (&$collected) {
        $collected[$version->version] = $attributes['title'];
    });

    expect($collected)->toHaveCount(12);
    for ($i = 1; $i <= 12; $i++) {
        expect($collected[$i])->toBe("V{$i}");
    }
});

it('does not mutate the model', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'V1 Title', 'body' => 'V1 Body']);
    $post->update(['title' => 'V2 Title']);
    $post->update(['title' => 'V3 Title']);

    $originalVersion = $post->current_version;
    $originalTitle = $post->title;

    Rewind::replay($post, 1, 3, function () {});

    expect($post->current_version)->toBe($originalVersion);
    expect($post->title)->toBe($originalTitle);
});

it('tracks multiple attribute changes across versions', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'T1', 'body' => 'B1']);
    $post->update(['title' => 'T2', 'body' => 'B2']);
    $post->update(['body' => 'B3']);

    $collected = [];
    Rewind::replay($post, 1, 3, function (RewindVersion $version, array $attributes) use (&$collected) {
        $collected[$version->version] = ['title' => $attributes['title'], 'body' => $attributes['body']];
    });

    expect($collected)->toBe([
        1 => ['title' => 'T1', 'body' => 'B1'],
        2 => ['title' => 'T2', 'body' => 'B2'],
        3 => ['title' => 'T2', 'body' => 'B3'],
    ]);
});
