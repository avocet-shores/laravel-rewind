<?php

use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\PostWithExcludedAttributes;
use AvocetShores\LaravelRewind\Tests\Models\Template;
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

it('creates a version when a model is created', function () {
    // Arrange: Ensure no versions exist
    $this->assertSame(0, RewindVersion::count());

    // Act: Create a Post
    Post::create([
        'user_id' => $this->user->id,
        'title' => 'Initial Title',
        'body' => 'This is the body content',
    ]);

    // Assert: One version should be created with the "old_values" mostly null,
    // and "new_values" reflecting the newly inserted record.
    $this->assertSame(1, RewindVersion::count());

    $version = RewindVersion::first();

    expect($version->new_values)->toMatchArray([
        'title' => 'Initial Title',
        'body' => 'This is the body content',
    ])
        ->and($version->old_values)->toMatchArray([
            'title' => null,
            'body' => null,
        ]);
});

it('creates a version when a model is updated', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Original Title',
        'body' => 'Original Body',
    ]);
    RewindVersion::query()->update(['created_at' => now()->subMinute()]);

    // Act: Update the post
    $post->title = 'Updated Title';
    $post->body = 'Updated Body';
    $post->save();

    // Assert: Versions should now be 2
    $this->assertSame(2, RewindVersion::count());

    $latestVersion = RewindVersion::orderBy('id', 'desc')->first();

    // Check that old and new values reflect the change
    expect($latestVersion->old_values)->toMatchArray([
        'title' => 'Original Title',
        'body' => 'Original Body',
    ])
        ->and($latestVersion->new_values)->toMatchArray([
            'title' => 'Updated Title',
            'body' => 'Updated Body',
        ]);
});

it('does not create a new version if nothing changes on save', function () {
    // Arrange
    $post = $this->user->posts()->create([
        'title' => 'No Change Title',
        'body' => 'No Change Body',
    ]);
    $originalVersionCount = RewindVersion::count();

    // Now retrieve post from the db and save it without making any changes
    $post = Post::find($post->id);
    $post->save();

    // Assert: Versions have not increased
    $this->assertSame($originalVersionCount, RewindVersion::count());
});

it('ignores excluded attributes defined by the model', function () {
    // Here we assume that the Post model has excluded the 'body' attribute
    // so that body changes won't be recorded.

    // Arrange
    $post = PostWithExcludedAttributes::create([
        'user_id' => $this->user->id,
        'title' => 'Tracked Title',
        'body' => 'Untracked Body',
    ]);

    // Assert: One version should be created
    $this->assertSame(1, $post->versions()->count());

    // Act: Get new post from db and update only the body
    $post = PostWithExcludedAttributes::find($post->id);
    $post->body = 'Updated Body';
    $post->save();

    // Assert: No new version should be created
    $this->assertSame(1, $post->versions()->count());

    // Act again: Update the title (which is not excluded)
    $post->title = 'Changed Title';
    $post->save();

    // Assert: Now we should see a new version
    $this->assertSame(2, $post->versions()->count());
});

it('creates a version when a model is deleted (if we want to track deletions)', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'Delete Me',
        'body' => 'Delete Body',
    ]);
    $this->assertSame(1, RewindVersion::count());

    // Act: Delete the model
    $post->delete();

    // Assert: Now we should have 0 versions, because the model does not implement soft deletes
    $this->assertSame(0, RewindVersion::count());

    // Ensure the model is actually deleted
    $this->assertDatabaseMissing('posts', ['id' => $post->id]);
});

it('does not record a version if disableRewindEvents is set to true before saving', function () {
    // Sometimes we want to skip auto-creation of versions, e.g. reverts.

    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'First Title',
        'body' => 'First Body',
    ]);
    $this->assertSame(1, RewindVersion::count());

    // Act: Temporarily disable events and then update
    $post->disableRewindEvents();
    $post->title = 'Second Title';
    $post->save();

    // Assert: No new version was created
    $this->assertSame(1, RewindVersion::count());
});

it('stores a version when soft deleting a model', function () {
    // Arrange
    $template = Template::create([
        'name' => 'Template 1',
        'content' => 'Test Content',
    ]);

    // Act: Soft delete the model
    $template->delete();

    // Assert: We should have 2 versions: one for creation and one for deletion
    $this->assertSame(2, $template->versions()->count());

    // deleted_at should be null in old_values and set in new_values
    $latestVersion = $template->versions()->where('version', 2)->first();
    expect($latestVersion->old_values)->toMatchArray([
        'deleted_at' => null,
    ])
        ->and($latestVersion->new_values)->toMatchArray([
            $template->getDeletedAtColumn() => $template->deleted_at->toDateTimeString(),
        ]);

    // Act: Restore the model
    $oldDeletedAt = $template->deleted_at;
    $template->restore();

    // Assert: We should have 3 versions: one for creation, one for deletion, and one for restoration
    $this->assertSame(3, $template->versions()->count());

    // deleted_at should be set in old_values and null in new_values
    $latestVersion = $template->versions()->where('version', 3)->first();
    expect($latestVersion->old_values)->toMatchArray([
        $template->getDeletedAtColumn() => $oldDeletedAt->toDateTimeString(),
    ])
        ->and($latestVersion->new_values)->toMatchArray([
            'deleted_at' => null,
        ]);

    // Force delete the model
    $template->forceDelete();

    // Assert: We should now have 0 versions and the model should be deleted
    $this->assertSame(0, $template->versions()->count());
    $this->assertDatabaseMissing('templates', ['id' => $template->id]);
});

it('creates a v1 snapshot of the model when no versions exist', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'First Title',
        'body' => 'First Body',
    ]);

    // Act: Delete the version
    $post->versions()->delete();

    // Assert: No versions exist
    $this->assertSame(0, $post->versions()->count());

    // Act: Create a new version
    $post->initVersion();

    // Assert: Now we should have a single version
    $this->assertSame(1, $post->versions()->count());
    $this->assertSame(1, $post->versions()->first()->version);
    $this->assertTrue($post->versions()->first()->is_snapshot);
    $this->assertSame('First Title', $post->versions()->first()->new_values['title']);
    $this->assertSame('First Body', $post->versions()->first()->new_values['body']);
});

it('does not create a v1 snapshot if versions already exist', function () {
    // Arrange
    $post = Post::create([
        'user_id' => $this->user->id,
        'title' => 'First Title',
        'body' => 'First Body',
    ]);

    // Act: Create a new version
    $post->initVersion();

    // Assert: We should still have only 1 version
    $this->assertSame(1, $post->versions()->count());
});

it('does not create a version when only excluded attributes change on a soft delete model', function () {
    // This test reproduces the bug where excluded attributes don't work with SoftDeletes models
    // Create a Template model with excluded attributes
    $template = new class extends Template
    {
        public static function excludedFromVersioning(): array
        {
            return ['content'];
        }
    };

    // Create the model
    $instance = $template->create([
        'name' => 'Test Template',
        'content' => 'Initial Content',
    ]);

    // Assert: One version should be created
    $this->assertSame(1, $instance->versions()->count());

    // Act: Update only the excluded attribute
    $instance = $template->find($instance->id);
    $instance->content = 'Updated Content';
    $instance->save();

    // Assert: No new version should be created because only an excluded attribute changed
    $this->assertSame(1, $instance->versions()->count());
});

it('creates a version when both excluded and non-excluded attributes change on a soft delete model', function () {
    // Test that versions are still created when non-excluded attributes change
    $template = new class extends Template
    {
        public static function excludedFromVersioning(): array
        {
            return ['content'];
        }
    };

    // Create the model
    $instance = $template->create([
        'name' => 'Test Template',
        'content' => 'Initial Content',
    ]);

    // Assert: One version should be created
    $this->assertSame(1, $instance->versions()->count());

    // Act: Update both excluded and non-excluded attributes
    $instance = $template->find($instance->id);
    $instance->name = 'Updated Template';
    $instance->content = 'Updated Content';
    $instance->save();

    // Assert: A new version should be created because a non-excluded attribute changed
    $this->assertSame(2, $instance->versions()->count());

    // The version should only track the non-excluded attribute
    $latestVersion = $instance->versions()->where('version', 2)->first();
    expect($latestVersion->old_values)->toHaveKey('name')
        ->and($latestVersion->new_values)->toHaveKey('name')
        ->and($latestVersion->old_values)->not->toHaveKey('content')
        ->and($latestVersion->new_values)->not->toHaveKey('content');
});
