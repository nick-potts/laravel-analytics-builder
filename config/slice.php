<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Eloquent Schema Provider
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic Eloquent model introspection.
    |
    */
    'eloquent' => [
        /*
        |--------------------------------------------------------------------------
        | Model Directories
        |--------------------------------------------------------------------------
        |
        | Directories to scan for Eloquent models. Paths are relative to the
        | application root.
        |
        | Example: ['app/Models', 'workbench/app/Models']
        |
        */
        'model_directories' => [
            'app/Models',
        ],

        /*
        |--------------------------------------------------------------------------
        | Enable Caching
        |--------------------------------------------------------------------------
        |
        | Cache introspection results for faster startup times in production.
        |
        */
        'cache' => env('SLICE_CACHE', true),
    ],
];
