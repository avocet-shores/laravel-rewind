<?php

namespace AvocetShores\LaravelRewind\Tests;

use AvocetShores\LaravelRewind\LaravelRewindServiceProvider;
use AvocetShores\LaravelRewind\Tests\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AvocetShores\\LaravelRewind\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelRewindServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('rewind.database_connection', 'sqlite');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        config()->set('auth.providers.users.model', User::class);
        config()->set('rewind.user_model', User::class);
        config()->set('app.key', 'base64:'.base64_encode(
            Encrypter::generateKey(config()['app.cipher'])
        ));
    }

    protected function setUpDatabase()
    {
        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (require $migration->getPathname())->up();
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->unsignedBigInteger('current_version')->nullable();
            $table->timestamps();
        });

        // Create a test table that implements soft deletes
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('content');
            $table->softDeletes();
            $table->timestamps();
        });

        // Create a test table with UUID primary key
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedBigInteger('current_version')->nullable();
            $table->timestamps();
        });

        // Create a test table with ULID primary key
        Schema::create('articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('author')->nullable();
            $table->unsignedBigInteger('current_version')->nullable();
            $table->timestamps();
        });
    }
}
