<?php

use AvocetShores\LaravelRewind\Facades\Rewind;
use AvocetShores\LaravelRewind\Models\RewindVersion;
use AvocetShores\LaravelRewind\Tests\Models\Article;
use AvocetShores\LaravelRewind\Tests\Models\Product;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a user for tracking
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    // Set the user as the currently authenticated user
    test()->actingAs($this->user);
});

// ============================================
// UUID Model Tests (Product)
// ============================================

it('creates a version when a UUID model is created', function () {
    // Arrange: Ensure no versions exist
    $this->assertSame(0, RewindVersion::count());

    // Act: Create a Product with UUID
    $product = Product::create([
        'name' => 'Laptop',
        'description' => 'A powerful laptop',
        'price' => 999.99,
    ]);

    // Assert: Verify UUID was generated
    expect($product->id)->toBeString()
        ->and(strlen($product->id))->toBe(36);

    // Assert: One version should be created
    $this->assertSame(1, RewindVersion::count());

    $version = RewindVersion::first();

    // Verify model_id is stored as UUID string
    expect($version->model_id)->toBe($product->id)
        ->and($version->model_type)->toBe(Product::class)
        ->and($version->new_values)->toMatchArray([
            'name' => 'Laptop',
            'description' => 'A powerful laptop',
            'price' => '999.99',
        ])
        ->and($version->old_values)->toMatchArray([
            'name' => null,
            'description' => null,
            'price' => null,
        ]);
});

it('creates a version when a UUID model is updated', function () {
    // Arrange: Create initial product
    $product = Product::create([
        'name' => 'Laptop',
        'description' => 'A powerful laptop',
        'price' => 999.99,
    ]);

    // Act: Update the product
    $product->name = 'Gaming Laptop';
    $product->price = 1299.99;
    $product->save();

    // Assert: Two versions should exist
    $this->assertSame(2, RewindVersion::count());

    $latestVersion = RewindVersion::orderBy('id', 'desc')->first();

    expect($latestVersion->model_id)->toBe($product->id)
        ->and($latestVersion->old_values)->toMatchArray([
            'name' => 'Laptop',
            'price' => '999.99',
        ])
        ->and($latestVersion->new_values)->toMatchArray([
            'name' => 'Gaming Laptop',
            'price' => '1299.99',
        ]);
});

it('can rewind a UUID model to a previous version', function () {
    // Arrange: Create and update a product
    $product = Product::create([
        'name' => 'Laptop',
        'description' => 'Original description',
        'price' => 999.99,
    ]);

    $product->update([
        'name' => 'Gaming Laptop',
        'description' => 'Updated description',
        'price' => 1299.99,
    ]);

    $product->update([
        'name' => 'Pro Gaming Laptop',
        'description' => 'Final description',
        'price' => 1599.99,
    ]);

    // Act: Rewind to version 1
    Rewind::goTo($product, 1);

    // Assert: Model should have original values
    expect($product->name)->toBe('Laptop')
        ->and($product->description)->toBe('Original description')
        ->and($product->price)->toBe('999.99')
        ->and($product->current_version)->toBe(1);
});

it('can fast forward a UUID model to a later version', function () {
    // Arrange: Create and update a product
    $product = Product::create([
        'name' => 'Laptop',
        'price' => 999.99,
    ]);

    $product->update(['name' => 'Gaming Laptop', 'price' => 1299.99]);
    $product->update(['name' => 'Pro Gaming Laptop', 'price' => 1599.99]);

    // Rewind to version 1
    Rewind::goTo($product, 1);

    // Act: Fast forward to version 3
    Rewind::goTo($product, 3);

    // Assert: Model should have final values
    expect($product->name)->toBe('Pro Gaming Laptop')
        ->and($product->price)->toBe('1599.99')
        ->and($product->current_version)->toBe(3);
});

it('correctly retrieves versions for a UUID model', function () {
    // Arrange: Create a product
    $product = Product::create([
        'name' => 'Laptop',
        'price' => 999.99,
    ]);

    // Act: Get versions
    $versions = $product->versions;

    // Assert
    expect($versions)->toHaveCount(1)
        ->and($versions->first()->model_id)->toBe($product->id)
        ->and($versions->first()->model_type)->toBe(Product::class);
});

// ============================================
// ULID Model Tests (Article)
// ============================================

it('creates a version when a ULID model is created', function () {
    // Arrange: Ensure no versions exist
    $this->assertSame(0, RewindVersion::count());

    // Act: Create an Article with ULID
    $article = Article::create([
        'title' => 'Introduction to Laravel',
        'content' => 'Laravel is a web application framework...',
        'author' => 'Jane Smith',
    ]);

    // Assert: Verify ULID was generated (26 characters)
    expect($article->id)->toBeString()
        ->and(strlen($article->id))->toBe(26);

    // Assert: One version should be created
    $this->assertSame(1, RewindVersion::count());

    $version = RewindVersion::first();

    // Verify model_id is stored as ULID string
    expect($version->model_id)->toBe($article->id)
        ->and($version->model_type)->toBe(Article::class)
        ->and($version->new_values)->toMatchArray([
            'title' => 'Introduction to Laravel',
            'content' => 'Laravel is a web application framework...',
            'author' => 'Jane Smith',
        ])
        ->and($version->old_values)->toMatchArray([
            'title' => null,
            'content' => null,
            'author' => null,
        ]);
});

it('creates a version when a ULID model is updated', function () {
    // Arrange: Create initial article
    $article = Article::create([
        'title' => 'Introduction to Laravel',
        'content' => 'Original content',
        'author' => 'Jane Smith',
    ]);

    // Act: Update the article
    $article->title = 'Advanced Laravel Techniques';
    $article->content = 'Updated content';
    $article->save();

    // Assert: Two versions should exist
    $this->assertSame(2, RewindVersion::count());

    $latestVersion = RewindVersion::orderBy('id', 'desc')->first();

    expect($latestVersion->model_id)->toBe($article->id)
        ->and($latestVersion->old_values)->toMatchArray([
            'title' => 'Introduction to Laravel',
            'content' => 'Original content',
        ])
        ->and($latestVersion->new_values)->toMatchArray([
            'title' => 'Advanced Laravel Techniques',
            'content' => 'Updated content',
        ]);
});

it('can rewind a ULID model to a previous version', function () {
    // Arrange: Create and update an article
    $article = Article::create([
        'title' => 'First Title',
        'content' => 'First content',
        'author' => 'Jane Smith',
    ]);

    $article->update([
        'title' => 'Second Title',
        'content' => 'Second content',
    ]);

    $article->update([
        'title' => 'Third Title',
        'content' => 'Third content',
    ]);

    // Act: Rewind to version 1
    Rewind::goTo($article, 1);

    // Assert: Model should have original values
    expect($article->title)->toBe('First Title')
        ->and($article->content)->toBe('First content')
        ->and($article->author)->toBe('Jane Smith')
        ->and($article->current_version)->toBe(1);
});

it('can fast forward a ULID model to a later version', function () {
    // Arrange: Create and update an article
    $article = Article::create([
        'title' => 'First Title',
        'content' => 'First content',
    ]);

    $article->update(['title' => 'Second Title', 'content' => 'Second content']);
    $article->update(['title' => 'Third Title', 'content' => 'Third content']);

    // Rewind to version 1
    Rewind::goTo($article, 1);

    // Act: Fast forward to version 3
    Rewind::goTo($article, 3);

    // Assert: Model should have final values
    expect($article->title)->toBe('Third Title')
        ->and($article->content)->toBe('Third content')
        ->and($article->current_version)->toBe(3);
});

it('correctly retrieves versions for a ULID model', function () {
    // Arrange: Create an article
    $article = Article::create([
        'title' => 'Test Article',
        'content' => 'Test content',
    ]);

    // Act: Get versions
    $versions = $article->versions;

    // Assert
    expect($versions)->toHaveCount(1)
        ->and($versions->first()->model_id)->toBe($article->id)
        ->and($versions->first()->model_type)->toBe(Article::class);
});

// ============================================
// Mixed Model Tests
// ============================================

it('can handle versions for both UUID and ULID models simultaneously', function () {
    // Arrange & Act: Create both types of models
    $product = Product::create([
        'name' => 'Laptop',
        'price' => 999.99,
    ]);

    $article = Article::create([
        'title' => 'Test Article',
        'content' => 'Test content',
    ]);

    // Assert: Two versions should exist
    $this->assertSame(2, RewindVersion::count());

    // Verify each version has the correct model_id
    $productVersion = RewindVersion::where('model_type', Product::class)->first();
    $articleVersion = RewindVersion::where('model_type', Article::class)->first();

    expect($productVersion->model_id)->toBe($product->id)
        ->and(strlen($productVersion->model_id))->toBe(36) // UUID length
        ->and($articleVersion->model_id)->toBe($article->id)
        ->and(strlen($articleVersion->model_id))->toBe(26); // ULID length
});
