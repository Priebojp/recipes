<?php

namespace App\Enums;

/**
 * Consent categories of the cookie banner. Marketing exists in the model but has no integration and is never
 * shown while no marketing service is registered and enabled (specification chapter 10 and 12).
 */
enum ConsentCategory: string
{
    case Necessary = 'necessary';
    case Analytics = 'analytics';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Necessary => 'Nevyhnutné',
            self::Analytics => 'Analytika',
            self::Marketing => 'Marketing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Necessary => 'Prihlásenie, bezpečnosť a zapamätanie vašej voľby. Nedajú sa vypnúť.',
            self::Analytics => 'Meranie používania aplikácie bez obsahu receptov a bez údajov o rodine. Spustí sa až po vašom súhlase.',
            self::Marketing => 'Reklamné technológie. Momentálne nepoužívame žiadne.',
        };
    }

    public function isOptional(): bool
    {
        return $this !== self::Necessary;
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return [self::Analytics, self::Marketing];
    }
}
