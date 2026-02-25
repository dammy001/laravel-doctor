<?php

return [
    'paths' => [
        base_path('app'),
        base_path('routes'),
        base_path('database'),
        base_path('config'),
        base_path('resources/views'),
    ],

    'extensions' => ['php'],

    'weights' => [
        'high' => 20,
        'medium' => 10,
        'low' => 4,
    ],

    'base_score' => 100,

    'categories' => [
        'security' => true,
        'performance' => true,
        'correctness' => true,
        'architecture' => true,
    ],

    'performance' => [
        'max_file_lines' => 400,
        'n_plus_one_threshold' => 0,
        'unbounded_get_max_per_file' => 6,
        'memory_growth_threshold_per_loop' => 20,
    ],

    'index_checks' => [
        'enabled' => true,
        'max_issues_per_file' => 25,
    ],

    'database_checks' => [
        'enabled' => true,
        'max_in_list_items' => 20,
    ],
];
