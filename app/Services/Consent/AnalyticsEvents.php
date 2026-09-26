<?php

namespace App\Services\Consent;

/**
 * Allowlist of analytics events (specification chapter 12). Nothing else leaves the application: no recipe
 * titles, ingredients, names, e-mails, prompts or billing data – only short enumerated values and numbers.
 */
class AnalyticsEvents
{
    /** @var array<string, list<string>> event => allowed property keys */
    public const EVENTS = [
        'recipe_created' => ['source'],
        'selection_started' => ['people', 'term'],
        'meal_planned' => ['mode'],
        'checkout_started' => ['offer'],
        'subscription_started' => ['offer'],
        'addon_purchased' => ['offer'],
    ];

    /** @var array<string, list<string>> property => allowed string values (numbers and booleans always pass) */
    public const VALUES = [
        'source' => ['manual', 'ai', 'import'],
        'term' => ['today', 'tomorrow', 'week'],
        'mode' => ['day', 'week'],
        'offer' => ['plus_monthly', 'plus_yearly', 'images_20_standard', 'text_100'],
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @return array{name: string, properties: array<string, bool|int|float|string>}|null null when the event is not allowed
     */
    public static function sanitize(string $name, array $properties = []): ?array
    {
        if (! array_key_exists($name, self::EVENTS)) {
            return null;
        }

        $clean = [];
        foreach ($properties as $key => $value) {
            if (! in_array($key, self::EVENTS[$name], true)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value) && in_array($value, self::VALUES[$key] ?? [], true)) {
                $clean[$key] = $value;
            }
        }

        return ['name' => $name, 'properties' => $clean];
    }
}
