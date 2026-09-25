<?php

return [
    'default_timezone' => env('RECIPES_DEFAULT_TIMEZONE', 'Europe/Bratislava'),

    /*
     * Weights of the random selection algorithm (chapter 7 of the specification).
     * Bump "config_version" whenever the numbers change so stored sessions can be recognised.
     */
    'selection' => [
        'config_version' => 1,
        'session_ttl_hours' => 48,
        'preference_scores' => [
            'favorite' => 2.0,
            'eats' => 1.0,
            'unrated' => 0.9,
            'dislikes' => 0.15,
        ],
        'group_min_weight' => 0.6,
        'group_avg_weight' => 0.4,
        'recency' => [
            // [max days inclusive => factor]; "null" means "never / 28+ days"
            [2, 0.15],
            [6, 0.35],
            [13, 0.65],
            [27, 0.85],
        ],
        'recency_default' => 1.0,
        'frequency_window_days' => 28,
        'frequency_coefficient' => 0.2,
        'other_group_base' => 0.7,
        'other_group_span' => 0.3,
        'planned_factor' => 0.4,
        'planned_window_days' => 3,
        'min_weight' => 0.02,
    ],

    'uploads' => [
        'max_kilobytes' => 10240,
        'max_dimension' => 6000,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    'ai' => [
        'text_provider' => env('RECIPES_AI_TEXT_PROVIDER', 'openai'),
        'text_model' => env('RECIPES_AI_TEXT_MODEL'),
        'image_provider' => env('RECIPES_AI_IMAGE_PROVIDER', 'openai'),
        'image_model' => env('RECIPES_AI_IMAGE_MODEL'),
        'text_prompt_version' => '1',
        'image_prompt_version' => '1',
        // Per household limits; the UI shows exhaustion before another run.
        'daily_text_limit' => (int) env('RECIPES_AI_DAILY_TEXT_LIMIT', 30),
        'daily_image_limit' => (int) env('RECIPES_AI_DAILY_IMAGE_LIMIT', 10),
        'max_concurrent_jobs' => (int) env('RECIPES_AI_MAX_CONCURRENT', 2),
        'timeout_seconds' => (int) env('RECIPES_AI_TIMEOUT', 120),
    ],

    'export' => [
        'schema_version' => 1,
    ],
];
