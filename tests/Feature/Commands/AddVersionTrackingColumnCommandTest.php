<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Ensure the migrations directory is clean before we start
    File::cleanDirectory(database_path('migrations'));
});

afterEach(function () {
    // Clean up after each test
    File::cleanDirectory(database_path('migrations'));
});

it('exits with an error if no table argument is provided and the user does not supply one', function () {
    // We'll mock user input by simulating an empty response to the prompt.
    artisan('rewind:add-version')
        ->expectsQuestion('Which table do you want to add the current_version column to?', '')
        ->expectsOutput('A table name is required.')
        ->assertExitCode(1);
});

it('creates a migration file if the table argument is provided', function () {
    // Ensure the migrations directory is clean before we start
    File::cleanDirectory(database_path('migrations'));

    $tableName = 'posts';

    artisan('rewind:add-version', ['table' => $tableName])
        ->expectsOutputToContain('Migration created:')
        ->expectsOutput("Don't forget to run 'php artisan migrate'!")
        ->assertExitCode(0);

    // Grab all files in the migrations folder
    $files = File::allFiles(database_path('migrations'));
    expect($files)->toHaveCount(1);

    // Check the filename structure
    $fileName = $files[0]->getFilename();
    expect($fileName)->toContain("add_current_version_to_{$tableName}_table.php");

    // Confirm the placeholder for 'DummyTable' was replaced with the actual table
    $contents = File::get(database_path('migrations/'.$fileName));
    expect($contents)->toContain("Schema::table('{$tableName}'");
});

it('creates a migration file if the user provides table name at the prompt', function () {
    // We'll omit the --table argument, so it will ask the user
    artisan('rewind:add-version')
        ->expectsQuestion('Which table do you want to add the current_version column to?', 'articles')
        ->expectsOutputToContain('Migration created:')
        ->expectsOutput("Don't forget to run 'php artisan migrate'!")
        ->assertExitCode(0);

    $files = File::allFiles(database_path('migrations'));
    expect($files)->toHaveCount(1);

    $fileName = $files[0]->getFilename();
    expect($fileName)->toContain('add_current_version_to_articles_table.php');
    $contents = File::get(database_path('migrations/'.$fileName));
    expect($contents)->toContain("Schema::table('articles'");
});
