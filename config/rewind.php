<?php

// config for AvocetShores/LaravelRewind
return [

    /*
    |--------------------------------------------------------------------------
    | Rewind Versions Table Name
    |--------------------------------------------------------------------------
    |
    | Here you may define the name of the table that stores the versions.
    | By default, it is set to "rewind_versions". You may override it
    | via an environment variable or update this value directly.
    |
    */

    'table_name' => env('LARAVEL_REWIND_TABLE', 'rewind_versions'),

    /*
    |--------------------------------------------------------------------------
    | Rewind Versions Table User ID Column
    |--------------------------------------------------------------------------
    |
    | Here you may define the name of the column that stores the user ID.
    | By default, it is set to "user_id". You may override it via an
    | environment variable or update this value directly.
    |
    */

    'user_id_column' => env('LARAVEL_REWIND_USER_ID_COLUMN', 'user_id'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Here you may define the model that represents the user table.
    | By default, it is set to "App\Models\User". You may override it
    | via an environment variable or update this value directly.
    |
    */

    'user_model' => env('LARAVEL_REWIND_USER_MODEL', 'App\Models\User'),

    /*
    |--------------------------------------------------------------------------
    | Rewind Versions Table Connection
    |--------------------------------------------------------------------------
    |
    | Here you may define the connection that the versions table uses.
    | By default, it is set to "null" which uses the default connection.
    | You may override it via an environment variable or update this value directly.
    |
    */

    'database_connection' => env('LARAVEL_REWIND_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Track Authenticated User
    |--------------------------------------------------------------------------
    |
    | If true, the package will automatically store the currently authenticated
    | user's ID in the versions table (when available). If your application
    | doesn't track or need user IDs, set this value to false.
    |
    */

    'track_user' => true,

    /*
    |--------------------------------------------------------------------------
    | Snapshot Interval
    |--------------------------------------------------------------------------
    |
    | Here you may define the interval between versions that should be stored
    | as a full snapshot. By default, it is set to 10, but you may adjust
    | this value to suit your application's needs. Higher values reduce
    | the amount of data stored at the cost of longer traversal times.
    |
    */

    'snapshot_interval' => env('LARAVEL_REWIND_SNAPSHOT_INTERVAL', 10),

    /*
    |--------------------------------------------------------------------------
    | Listener Should Queue
    |--------------------------------------------------------------------------
    |
    | If true, the package will queue the Create Rewind Version listener that handles the RewindVersionCreating event.
    | If false, the listener will run synchronously.
    |
    */

    'listener_should_queue' => env('LARAVEL_REWIND_LISTENER_SHOULD_QUEUE', false),
];
