<?php

use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Post;
use AvocetShores\LaravelRewind\Tests\Models\Template;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    test()->actingAs($this->user);
});

it('exits with error when neither --days nor --keep is provided', function () {
    artisan('rewind:prune', ['--force' => true])
        ->expectsOutput('You must specify at least one of --days or --keep (or set defaults in config/rewind.php).')
        ->assertExitCode(1);
});

it('uses config defaults when no options provided', function () {
    config()->set('rewind.prune_keep_versions', 5);

    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(10);

    artisan('rewind:prune', ['--force' => true])
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(5);
});

it('prunes by count keeping the last N versions', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 15; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    expect(RewindVersion::count())->toBe(15);

    artisan('rewind:prune', ['--keep' => 5, '--force' => true])
        ->expectsOutputToContain('Successfully pruned 10 version record(s).')
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(5);

    // The oldest remaining version should be a snapshot
    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->is_snapshot)->toBeTrue();
    expect($oldest->new_values)->toBeArray();
    expect($oldest->new_values)->toHaveKey('title');
});

it('prunes by age deleting versions older than N days', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Backdate the first 6 versions to 60 days ago
    RewindVersion::where('version', '<=', 6)->update([
        'created_at' => now()->subDays(60),
    ]);

    artisan('rewind:prune', ['--days' => 30, '--force' => true])
        ->expectsOutputToContain('Successfully pruned 6 version record(s).')
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(4);
    $oldest = RewindVersion::orderBy('version')->first();
    expect($oldest->version)->toBe(7);
});

it('supports pretend mode without actually deleting', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    artisan('rewind:prune', ['--keep' => 3, '--pretend' => true])
        ->expectsOutputToContain('[Pretend] Would delete 7 version record(s).')
        ->assertExitCode(0);

    // Nothing should actually be deleted
    expect(RewindVersion::count())->toBe(10);
});

it('scopes pruning to specific model types', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Prune with a model type that doesn't match
    artisan('rewind:prune', ['--keep' => 3, '--model' => ['App\\Models\\SomeOtherModel'], '--force' => true])
        ->expectsOutputToContain('No version records matched the pruning criteria.')
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(10);

    // Prune with matching model type
    $morphClass = (new Post)->getMorphClass();
    artisan('rewind:prune', ['--keep' => 3, '--model' => [$morphClass], '--force' => true])
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(3);
});

it('asks for confirmation without --force', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 5; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Decline confirmation
    artisan('rewind:prune', ['--keep' => 2])
        ->expectsConfirmation('This will permanently delete rewind version records. Are you sure?', 'no')
        ->expectsOutput('Pruning cancelled.')
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(5);
});

it('reports no records when nothing matches', function () {
    artisan('rewind:prune', ['--keep' => 5, '--force' => true])
        ->expectsOutput('No version records matched the pruning criteria.')
        ->assertExitCode(0);
});

it('never deletes the latest version', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    $post->update(['title' => 'v2']);

    // Try to keep 0 - should still keep the latest
    artisan('rewind:prune', ['--keep' => 1, '--force' => true])
        ->assertExitCode(0);

    expect(RewindVersion::count())->toBe(1);
    $remaining = RewindVersion::first();
    expect($remaining->version)->toBe(2);
});

it('shows per-model-type breakdown when pruning multiple model types', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 6; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    $template = Template::create(['name' => 'v1', 'content' => 'content']);
    for ($i = 2; $i <= 6; $i++) {
        $template->update(['name' => "v{$i}"]);
    }

    $postMorph = (new Post)->getMorphClass();
    $templateMorph = (new Template)->getMorphClass();

    artisan('rewind:prune', ['--keep' => 2, '--force' => true])
        ->expectsOutputToContain("- {$postMorph}:")
        ->expectsOutputToContain("- {$templateMorph}:")
        ->assertExitCode(0);
});

it('handles combined --days and --keep', function () {
    $post = Post::create(['user_id' => $this->user->id, 'title' => 'v1', 'body' => 'body']);
    for ($i = 2; $i <= 10; $i++) {
        $post->update(['title' => "v{$i}"]);
    }

    // Backdate versions 1-5 to 60 days ago
    RewindVersion::where('version', '<=', 5)->update([
        'created_at' => now()->subDays(60),
    ]);

    // --keep=3 means versions 8,9,10 are protected
    // --days=30 means from versions 1-7, only 1-5 are old enough to prune
    artisan('rewind:prune', ['--keep' => 3, '--days' => 30, '--force' => true])
        ->assertExitCode(0);

    // Versions 1-5 pruned (old AND beyond keep), versions 6-7 kept (not old enough), versions 8-10 kept (within --keep)
    expect(RewindVersion::count())->toBe(5);
});
