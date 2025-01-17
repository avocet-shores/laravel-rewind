<?php

use AvocetShores\LaravelRewind\Exceptions\ModelNotRewindableException;
use AvocetShores\LaravelRewind\Exceptions\VersionDoesNotExistException;
use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostThatIsNotRewindable;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a user
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    // Set the user as the currently authenticated user
    test()->actingAs($this->user);
});

it('rewinds a model to the previous version on rewind', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Rewind the last version
    Rewind::rewind($post);

    // Assert: The model should be reverted to the previous version
    $this->assertSame(1, $post->current_version);
    $this->assertSame('Original Title', $post->title);
    $this->assertSame('Original Body', $post->body);
});

it('rewinds a model to the next version on fast-forward', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Undo the last version
    Rewind::rewind($post);

    // Assert: The model should be reverted to the previous version
    $this->assertSame(1, $post->current_version);
    $this->assertSame('Original Title', $post->title);
    $this->assertSame('Original Body', $post->body);

    // Act: Fast-forward the last version
    Rewind::fastForward($post);

    // Assert: The model should be reverted to the next version
    $this->assertSame(2, $post->current_version);
    $this->assertSame('Updated Title', $post->title);
    $this->assertSame('Updated Body', $post->body);
});

it('creates a new max version when a model is updated while on a previous version', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Undo the last version
    Rewind::rewind($post);

    // Assert: The model should be reverted to the previous version
    $this->assertSame(1, $post->current_version);
    $this->assertSame('Original Title', $post->title);
    $this->assertSame('Original Body', $post->body);

    // Act: Update the model again
    $post->update([
        'title' => 'Updated Title Again',
        'body' => 'Updated Body Again',
    ]);

    // Assert: The model should now be at version 3
    $this->assertSame(3, $post->current_version);
    $this->assertSame('Updated Title Again', $post->title);
    $this->assertSame('Updated Body Again', $post->body);
});

it('can jump to a specified version', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Jump to version 1
    Rewind::goTo($post, 1);

    // Assert: The model should be reverted to the previous version
    $this->assertSame(1, $post->current_version);
    $this->assertSame('Original Title', $post->title);
    $this->assertSame('Original Body', $post->body);

    // Act: Jump to version 2
    Rewind::goTo($post, 2);

    // Assert: The model should be back to the latest version
    $this->assertSame(2, $post->current_version);
    $this->assertSame('Updated Title', $post->title);
    $this->assertSame('Updated Body', $post->body);
});

it('can handle complex version history and goTo calls', function () {
    // Set config to snapshot every 5 versions
    config(['rewind.snapshot_interval' => 5]);

    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $this->assertDatabaseHas('rewind_versions', [
        'model_id' => $post->id,
        'version' => 1,
        'old_values' => json_encode([
            'user_id' => null,
            'title' => null,
            'body' => null,
        ]),
        'new_values' => json_encode([
            'user_id' => $this->user->id,
            'title' => 'Original Title',
            'body' => 'Original Body',
        ]),
        'is_snapshot' => 1,
    ]);

    $post->update([
        'title' => 'Updated Title',
    ]);

    $this->assertSame(2, $post->current_version);
    $version2 = $post->versions()->where('version', 2)->first();
    $this->assertNotNull($version2);
    $this->assertSame($version2->new_values['title'], $post->title);
    $this->assertArrayNotHasKey('body', $version2->new_values);
    $this->assertArrayNotHasKey('body', $version2->old_values);

    $post->update([
        'body' => 'Updated Body',
    ]);

    $this->assertSame(3, $post->current_version);
    $version3 = $post->versions()->where('version', 3)->first();
    $this->assertNotNull($version3);
    $this->assertSame($version3->new_values['body'], $post->body);

    Rewind::goTo($post, 1);

    $this->assertSame(1, $post->current_version);
    $this->assertSame('Original Title', $post->title);
    $this->assertSame('Original Body', $post->body);

    // Now make a new change, which should create a new version
    $post->update([
        'title' => 'Updated Title Again',
    ]);

    $this->assertSame(4, $post->current_version);
    $this->assertSame('Updated Title Again', $post->title);
    $this->assertSame('Original Body', $post->body);

    // Now rewind, which will actually take us back to version 3
    Rewind::rewind($post);

    $this->assertSame(3, $post->current_version);
    $this->assertSame('Updated Title', $post->title);
    $this->assertSame('Updated Body', $post->body);

    // Now fast-forward, which will take us back to version 4
    Rewind::fastForward($post);

    $post->update([
        'title' => 'Updated Title Yet Again',
    ]);

    $this->assertSame(5, $post->current_version);
    $version5 = $post->versions()->where('version', 5)->first();
    $this->assertNotNull($version5);
    $this->assertSame($version5->new_values['title'], $post->title);
    $this->assertTrue($version5->is_snapshot);
    $this->assertSame($version5->new_values['body'], $post->body);
    $this->assertSame($version5->new_values['user_id'], $this->user->id);

    $post->update([
        'body' => 'Updated Body Again',
    ]);

    $this->assertSame(6, $post->current_version);

    $post->update([
        'title' => 'Updated Title One More Time',
    ]);

    // Snapshot is a closer walk than direct, so this should jump from v5 down to v4
    Rewind::goTo($post, 4);

    $this->assertSame(4, $post->current_version);
    $this->assertSame('Updated Title Again', $post->title);
    $this->assertSame('Original Body', $post->body);

    Rewind::rewind($post);

    // Now make a final change
    $post->update([
        'title' => 'Updated Title One Last Time',
    ]);

    $finalVersion = $post->versions()->orderBy('version', 'desc')->first();
    $this->assertSame(8, $post->current_version);
    $this->assertSame('Updated Title One Last Time', $post->title);
    $this->assertSame('Updated Body', $post->body);
});

it('can clone a model with a given versions data', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Clone the model at version 1
    $clonedPost = Rewind::cloneModel($post, 1);

    // Assert: The cloned model should have the data from version 1
    $this->assertSame('Original Title', $clonedPost->title);
    $this->assertSame('Original Body', $clonedPost->body);
    $this->assertSame(1, $clonedPost->current_version);
});

it('can return an array of the version\'s attributes', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Act: Get the attributes of version 1
    $version1Attributes = Rewind::getVersionAttributes($post, 1);

    // Assert: The attributes should match the data from version 1
    $this->assertSame('Original Title', $version1Attributes['title']);
    $this->assertSame('Original Body', $version1Attributes['body']);
});

it('throws an exception when jumping to a version that does not exist', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->refresh();

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    // Act: Jump to version 2
    Rewind::goTo($post, 2);
})->throws(VersionDoesNotExistException::class);

it('is able to fast-forward to a snapshot', function () {
    app()->config->set('rewind.snapshot_interval', 3);

    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->refresh();

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    $this->assertSame(2, $post->current_version);

    // Snapshot
    $post->update([
        'title' => 'Updated Title Again',
        'body' => 'Updated Body Again',
    ]);

    $this->assertSame(3, $post->current_version);

    Rewind::rewind($post);

    $this->assertSame(2, $post->current_version);

    // Act: Fast-forward to the snapshot
    Rewind::fastForward($post);

    // Assert: The model should be at the snapshot version
    $this->assertSame(3, $post->current_version);
    $this->assertSame('Updated Title Again', $post->title);
    $this->assertSame('Updated Body Again', $post->body);
});

it('moves to the lowest available version when we try to rewind before version 1', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->refresh();

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    // Act: Rewind the last version
    Rewind::rewind($post);

    // Assert: The model should be at the lowest version
    $this->assertSame(1, $post->current_version);
});

it('moves the model to the highest available version when trying to fast-forward past the latest version', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    // Assert the model has current_version set to 1
    $this->assertSame(1, $post->current_version);

    // Act: Fast-forward the last version
    Rewind::fastForward($post);

    // Assert: The model should be at the latest version
    $this->assertSame(1, $post->current_version);
});

it('throws an exception when the model is not rewindable', function () {
    // Arrange
    $post = PostThatIsNotRewindable::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);

    $post->update([
        'title' => 'Updated Title',
        'body' => 'Updated Body',
    ]);

    // Act: Rewind the last version
    Rewind::rewind($post);
})->throws(ModelNotRewindableException::class);
