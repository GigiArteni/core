<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'limit' => 15
    ],

    /*
    |--------------------------------------------------------------------------
    | Enhanced Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('REPOSITORY_CACHE_ENABLED', true),
        'minutes' => env('REPOSITORY_CACHE_MINUTES', 30),
        'repository' => 'cache',
        'clean' => [
            'enabled' => env('REPOSITORY_CACHE_CLEAN_ENABLED', true),
            'on' => [
                'create' => true,
                'update' => true,
                'delete' => true,
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Transaction Settings
    |--------------------------------------------------------------------------
    | Smart transaction handling for data integrity
    */
    'transactions' => [
        'auto_wrap_bulk' => env('REPOSITORY_AUTO_TRANSACTION_BULK', true), // Auto-wrap bulk operations
        'auto_wrap_single' => env('REPOSITORY_AUTO_TRANSACTION_SINGLE', false), // Manual control for single ops
        'timeout' => env('REPOSITORY_TRANSACTION_TIMEOUT', 30), // Transaction timeout in seconds
        'retry_deadlocks' => env('REPOSITORY_RETRY_DEADLOCKS', true), // Auto-retry on deadlock
        'max_retries' => env('REPOSITORY_MAX_RETRIES', 3),
        'retry_delay' => env('REPOSITORY_RETRY_DELAY', 100), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Bulk Operations
    |--------------------------------------------------------------------------
    | Enhanced bulk operations
    */
    'bulk_operations' => [
        'enabled' => env('REPOSITORY_BULK_OPERATIONS', true),
        'chunk_size' => env('REPOSITORY_BULK_CHUNK_SIZE', 1000), // Process in chunks
        'use_transactions' => env('REPOSITORY_BULK_TRANSACTIONS', true),
        'log_performance' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Apiato v.13 Integration
    |--------------------------------------------------------------------------
    */
    'apiato' => [
        'performance' => [
            'enhanced_caching' => env('REPOSITORY_ENHANCED_CACHE', true),
            'query_optimization' => env('REPOSITORY_QUERY_OPTIMIZATION', true),
            'eager_loading_detection' => env('REPOSITORY_EAGER_LOADING_DETECTION', true),
        ],
        'features' => [
            'enhanced_search' => env('REPOSITORY_ENHANCED_SEARCH', false),
            'auto_cache_tags' => env('REPOSITORY_AUTO_CACHE_TAGS', true),
            'smart_relationships' => env('REPOSITORY_SMART_RELATIONSHIPS', true),
            'event_dispatching' => env('REPOSITORY_EVENT_DISPATCHING', true),
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Settings
    |--------------------------------------------------------------------------
    */
    'advanced' => [
        'bulk_chunk_size' => env('REPOSITORY_BULK_CHUNK_SIZE', 1000),
        'use_transactions' => env('REPOSITORY_BULK_TRANSACTIONS', true),
        'log_performance' => env('REPOSITORY_LOG_PERFORMANCE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | HashId Decoding
    |--------------------------------------------------------------------------
    | Enable or disable automatic HashId decoding for all repositories
    */
    'hashid_decode' => env('REPOSITORY_HASHID_DECODE', true),

    /*
    |--------------------------------------------------------------------------
    | Eager Loading via Query Parameter
    |--------------------------------------------------------------------------
    | Enable or disable automatic eager loading of relations via the `include` query parameter.
    | Supports dot notation (e.g. ?include=user.roles.permissions)
    */
    'eager_load_includes' => env('REPOSITORY_EAGER_LOAD_INCLUDES', true),
];
