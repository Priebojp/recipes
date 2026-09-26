<?php

use App\Services\Consent\AnalyticsEvents;

it('lets only allowlisted events and enumerated values through – never recipe content, e-mails or names', function () {
    expect(AnalyticsEvents::sanitize('recipe_viewed'))->toBeNull()
        ->and(AnalyticsEvents::sanitize('recipe_created', ['source' => 'ai', 'title' => 'Babkina praženica', 'email' => 'a@b.c']))->toBe(['name' => 'recipe_created', 'properties' => ['source' => 'ai']])
        ->and(AnalyticsEvents::sanitize('recipe_created', ['source' => 'Babkina praženica']))->toBe(['name' => 'recipe_created', 'properties' => []])
        ->and(AnalyticsEvents::sanitize('selection_started', ['people' => 3, 'term' => 'today', 'names' => ['Eva']]))->toBe(['name' => 'selection_started', 'properties' => ['people' => 3, 'term' => 'today']])
        ->and(AnalyticsEvents::sanitize('checkout_started', ['offer' => 'plus_yearly', 'amount' => 24.0]))->toBe(['name' => 'checkout_started', 'properties' => ['offer' => 'plus_yearly']]);
});
