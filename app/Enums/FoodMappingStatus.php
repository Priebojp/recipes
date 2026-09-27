<?php

namespace App\Enums;

/**
 * Lifecycle of the link between an ingredient line and a food record.
 */
enum FoodMappingStatus: string
{
    /** Proposed by the matcher, not yet seen by a person. */
    case Suggested = 'suggested';

    /** A member of the household confirmed food, state and grams. */
    case Confirmed = 'confirmed';

    /** A person said the proposal is wrong and no better food was chosen. */
    case Rejected = 'rejected';

    /** No candidate, or a candidate without a usable amount (no confirmed conversion, "podľa chuti"). */
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'návrh',
            self::Confirmed => 'potvrdené',
            self::Rejected => 'odmietnuté',
            self::Unresolved => 'nepriradené',
        };
    }
}
