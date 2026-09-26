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
        // Reasoning effort sent to OpenAI reasoning models (gpt-5/gpt-6 family): default|low|medium|high.
        // "default" sends nothing and lets the provider decide. Overridable at runtime in /admin → AI nastavenia.
        'text_reasoning_effort' => env('RECIPES_AI_TEXT_REASONING_EFFORT', 'low'),
        // Image profile "Standard" from the v2 specification: medium quality, 1024 × 1024, one image.
        'image_quality' => env('RECIPES_AI_IMAGE_QUALITY', 'medium'),
        'image_size' => env('RECIPES_AI_IMAGE_SIZE', '1:1'),
        'text_prompt_version' => '1',
        'image_prompt_version' => '1',
        // Per household limits; the UI shows exhaustion before another run.
        'daily_text_limit' => (int) env('RECIPES_AI_DAILY_TEXT_LIMIT', 30),
        'daily_image_limit' => (int) env('RECIPES_AI_DAILY_IMAGE_LIMIT', 10),
        'max_concurrent_jobs' => (int) env('RECIPES_AI_MAX_CONCURRENT', 2),
        'timeout_seconds' => (int) env('RECIPES_AI_TIMEOUT', 120),
        // Soft monthly budget for the admin dashboard (USD, provider list prices). Alarm only – never cuts paid usage.
        'monthly_budget_usd' => env('RECIPES_AI_MONTHLY_BUDGET_USD'),
        // Global kill switch default (runtime value lives in app_settings, editable in /admin).
        'enabled' => (bool) env('RECIPES_AI_ENABLED', true),
    ],

    /*
     * Ledger of AI uses (v2 stage 2). When enforced, every AI job reserves one use from a household grant
     * (trial → monthly → purchased) and settles it once the result is delivered or definitively failed.
     * Set enforce=false to keep the pre-ledger behaviour (daily limits only) during a staged rollout.
     */
    'usage' => [
        'enforce' => (bool) env('RECIPES_USAGE_ENFORCE', true),
        // One-time trial per verified user and per household; never re-granted by the rollout or a new household.
        'trial' => [
            'text' => (int) env('RECIPES_USAGE_TRIAL_TEXT', 3),
            'image_standard' => (int) env('RECIPES_USAGE_TRIAL_IMAGES', 1),
        ],
    ],

    /*
     * Stripe catalogue mapping (v2 stage 3). The client only ever sends an internal offer code; prices and Stripe
     * price IDs live in the versioned catalogue tables (plan_versions / addon_versions) seeded from these values.
     * Test and live IDs differ per environment; a missing ID blocks checkout of that offer, nothing else.
     */
    'billing' => [
        'timezone' => env('RECIPES_BILLING_TIMEZONE', 'Europe/Bratislava'),
        'renewal_grace_days' => (int) env('RECIPES_BILLING_GRACE_DAYS', 3),
        'pending_order_ttl_hours' => 24,
        // Optional USD→EUR rate for the admin dashboard's "contribution after variable costs" estimate. AI costs are
        // measured in USD; without a rate the dashboard shows them separately and computes no contribution.
        'usd_eur_rate' => env('RECIPES_BILLING_USD_EUR_RATE') !== null ? (float) env('RECIPES_BILLING_USD_EUR_RATE') : null,
        'stripe_prices' => [
            'plus_monthly' => env('STRIPE_PRICE_PLUS_MONTHLY'),
            'plus_yearly' => env('STRIPE_PRICE_PLUS_YEARLY'),
            'images_20_standard' => env('STRIPE_PRICE_IMAGES_20_STANDARD'),
            'text_100' => env('STRIPE_PRICE_TEXT_100'),
        ],
    ],

    'export' => [
        'schema_version' => 1,
    ],
];
