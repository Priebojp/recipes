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
        // Default image profile for new jobs (v2.1 stage 8): low = image_economy_v1, medium = image_standard_v1.
        // Profiles fix quality, 1024 × 1024 and one image; the runtime default is overridable in /admin → AI nastavenia.
        'image_quality' => env('RECIPES_AI_IMAGE_QUALITY', 'medium'),
        'text_prompt_version' => '1',
        'image_prompt_version' => '1',
        'meal_analysis_prompt_version' => '1',
        // Per household limits; the UI shows exhaustion before another run.
        'daily_text_limit' => (int) env('RECIPES_AI_DAILY_TEXT_LIMIT', 30),
        'daily_image_limit' => (int) env('RECIPES_AI_DAILY_IMAGE_LIMIT', 10),
        // Frequency cap for photo analyses (v2.1 stage 11); it also counts attempts that were not charged.
        'daily_meal_analysis_limit' => (int) env('RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT', 10),
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
            // Economy images are not offered until the v2.1 comparison decides (stage 13); 0 = no trial grant.
            'image_economy' => 0,
            // v2.1 stage 11: three photo analyses once per verified user, separate from the text trial.
            'meal_analysis' => (int) env('RECIPES_USAGE_TRIAL_MEAL_ANALYSES', 3),
        ],
    ],

    /*
     * Photo analysis of a meal (v2.1 stage 11). Photos live on a private disk and are working material: deleted
     * a short time after the analysis finishes unless the person keeps them with the record; unfinished proposals
     * are deleted after the draft TTL. Both are product retention settings, applied by app:meal-analysis-cleanup.
     */
    'meal_analysis' => [
        'disk' => env('RECIPES_MEAL_PHOTO_DISK', 'local'),
        'photo_ttl_hours' => (int) env('RECIPES_MEAL_PHOTO_TTL_HOURS', 24),
        'draft_ttl_days' => (int) env('RECIPES_MEAL_DRAFT_TTL_DAYS', 7),
        // Longest edge sent to the provider; larger uploads are shrunk (and always re-encoded without EXIF/GPS).
        'max_dimension' => 1536,
        // Follow-up questions answered with AI in the same session without a second use.
        'max_clarifications' => 2,
        'max_items' => 30,
    ],

    /*
     * Stripe catalogue mapping (v2 stage 3). The client only ever sends an internal offer code; prices and Stripe
     * price IDs live in the versioned catalogue tables (plan_versions / addon_versions) seeded from these values.
     * Test and live IDs differ per environment; a missing ID blocks checkout of that offer, nothing else.
     */
    'billing' => [
        // Deploy-time switch for paid checkout (v2 stage 7). Off by default: a fresh deployment sells nothing until the
        // operator flips it in a separate, controlled deployment after the launch checklist passes. Recipes, free
        // features and existing paid entitlements are never affected by this switch.
        'checkout_enabled' => (bool) env('RECIPES_CHECKOUT_ENABLED', false),
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
            // v2.1 stage 13 packs; sold only once the administrator activates them in /admin/catalog.
            'meal_analyses_100' => env('STRIPE_PRICE_MEAL_ANALYSES_100'),
            'images_economy_20' => env('STRIPE_PRICE_IMAGES_ECONOMY_20'),
        ],
    ],

    /*
     * Food database (v2.1 stage 9). One provider (USDA FoodData Central), answers cached, application-side hourly
     * limit well under the provider's 1 000 req/h/IP. Without USDA_FDC_API_KEY the stored dictionary works and
     * searching for new foods is off with a clear message.
     */
    'food' => [
        'cache_hours' => (int) env('RECIPES_FOOD_CACHE_HOURS', 24 * 7),
        'rate_limit_per_hour' => (int) env('RECIPES_FOOD_RATE_LIMIT_PER_HOUR', 900),
        'timeout_seconds' => 15,
        'search_data_types' => ['SR Legacy', 'Foundation'],
    ],

    'export' => [
        // 2: selection_presets and shopping_lists added (v2 stage 6); older keys are unchanged.
        // 3: ingredients[].food_mapping and recipes[].nutrition_calculations added (v2.1 stage 10).
        'schema_version' => 3,
    ],

    /*
     * Legal pages, consent and privacy (v2 stage 5). Product settings, not statements about statutory periods.
     */
    'legal' => [
        // Statutory withdrawal window shown to customers; the online form accepts later requests too (reviewed manually).
        'withdrawal_days' => 14,
    ],
    'consent' => [
        // How long a cookie decision is remembered before the banner asks again (specification: 6 months proposal).
        'lifetime_days' => (int) env('RECIPES_CONSENT_LIFETIME_DAYS', 180),
    ],
    'privacy' => [
        // Internal deadline for answering a data-subject request.
        'request_deadline_days' => 30,
    ],
];
