<?php

return [
    /*
     * Platform administration (/admin). The platform administrator is a different role than a household owner.
     * The role is granted only by the deploy command `app:grant-platform-admin`, never by registration or mass assignment.
     */

    // The admin panel refuses users without confirmed two-factor authentication (the specification requires MFA).
    'require_two_factor' => (bool) env('ADMIN_REQUIRE_TWO_FACTOR', true),

    // Used by `php artisan db:seed` (PlatformAdminSeeder) to bootstrap the first administrator.
    'bootstrap_email' => env('ADMIN_EMAIL', 'support@moje-recepty.sk'),
    'bootstrap_name' => env('ADMIN_NAME', 'Podpora Moje recepty'),
    // Only read by the seeder; outside production it falls back to "password". Change it after the first login.
    'bootstrap_password' => env('ADMIN_INITIAL_PASSWORD'),

    // How long the admin AI dashboards look back by default.
    'usage_default_days' => 30,
];
