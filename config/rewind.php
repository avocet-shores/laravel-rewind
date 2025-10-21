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

    /*
    |--------------------------------------------------------------------------
    | Concurrency Settings
    |--------------------------------------------------------------------------
    |
    | Define how long to wait (in seconds) for lock acquisition before timing out,
    | and how long the lock should remain valid if the process unexpectedly ends.
    */

    'lock_wait' => env('REWIND_LOCK_WAIT', 20),
    'lock_timeout' => env('REWIND_LOCK_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Version Pruning
    |--------------------------------------------------------------------------
    |
    | Configure retention policies for version records. Set limits based on
    | age, count, or both. When both policies are set, versions are only
    | deleted if they violate both policies (most permissive retention).
    |
    */

    'pruning' => [
        /*
        | Retention by age: Keep versions created within the last X days.
        | Set to null to disable age-based retention.
        */
        'retention_days' => env('REWIND_RETENTION_DAYS', null),

        /*
        | Retention by count: Keep the last X versions per model instance.
        | Set to null to disable count-based retention.
        */
        'retention_count' => env('REWIND_RETENTION_COUNT', null),

        /*
        | Preserve snapshots that fall on the configured snapshot interval.
        | Snapshots are needed for efficient version reconstruction.
        */
        'keep_snapshots' => env('REWIND_KEEP_SNAPSHOTS', true),

        /*
        | Always preserve version 1 (initial state) for each model instance.
        | Recommended for maintaining complete history.
        */
        'keep_version_one' => env('REWIND_KEEP_VERSION_ONE', true),

        /*
        | Process deletions in chunks to prevent memory exhaustion.
        */
        'chunk_size' => env('REWIND_PRUNE_CHUNK_SIZE', 1000),
    ],
];
