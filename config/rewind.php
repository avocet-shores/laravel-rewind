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
    | Rewind Versions Table User ID Cast
    |--------------------------------------------------------------------------
    |
    | Here you may define the Eloquent cast type for the user ID column.
    | By default, it is set to "integer". If your User model uses UUID
    | or string primary keys, change this to "string".
    |
    */

    'user_id_cast' => env('LARAVEL_REWIND_USER_ID_CAST', 'integer'),

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
    | Max Versions Per Model
    |--------------------------------------------------------------------------
    |
    | If set, the package will automatically prune old versions after creating
    | a new one, keeping at most this many versions per model instance. Pruning
    | is batched using snapshot_interval as a buffer — versions accumulate to
    | max_versions + snapshot_interval before pruning back to max_versions.
    | This can be overridden per-model by defining a maxRewindVersions() method.
    | Set to null to disable automatic pruning.
    |
    */

    'max_versions' => env('LARAVEL_REWIND_MAX_VERSIONS'),

    /*
    |--------------------------------------------------------------------------
    | Prune Command Defaults
    |--------------------------------------------------------------------------
    |
    | These values serve as defaults for the rewind:prune Artisan command.
    | They can be overridden at runtime via --keep and --days options.
    |
    | prune_keep_versions: Keep the last N versions per model instance.
    | prune_older_than_days: Delete versions older than N days.
    |
    */

    'prune_keep_versions' => env('LARAVEL_REWIND_PRUNE_KEEP'),

    'prune_older_than_days' => env('LARAVEL_REWIND_PRUNE_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout Behavior
    |--------------------------------------------------------------------------
    |
    | Determines what happens when a lock cannot be acquired for version creation.
    | Supported values: "log", "event", "throw"
    |
    | - "log":   (default) Logs an error message. Silent failure.
    | - "event": Logs the error AND dispatches a RewindVersionLockTimeout event
    |            so you can attach listeners for alerting or custom retry logic.
    | - "throw": Logs the error, dispatches the event, AND throws a
    |            LockTimeoutRewindException. For queued listeners, this allows
    |            Laravel's built-in retry mechanism ($tries, $backoff) to retry.
    |
    */

    'on_lock_timeout' => env('LARAVEL_REWIND_ON_LOCK_TIMEOUT', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | When listener_should_queue is true, these settings control the retry
    | behavior of the queued version creation listener. Adjust these values
    | to suit your application's reliability requirements.
    |
    */

    'queue' => [
        'tries' => env('LARAVEL_REWIND_QUEUE_TRIES', 3),
        'backoff' => [2, 10, 30],
        'timeout' => env('LARAVEL_REWIND_QUEUE_TIMEOUT', 60),
    ],
];
