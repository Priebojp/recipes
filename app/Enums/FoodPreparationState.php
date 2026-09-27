<?php

namespace App\Enums;

/**
 * State of a food as the source measured it. Raw rice and cooked rice are different records with different
 * values per 100 g; the state travels with every alias, record and mapping so they are never mixed up.
 */
enum FoodPreparationState: string
{
    case Raw = 'raw';
    case Cooked = 'cooked';
    case Dry = 'dry';
    case Canned = 'canned';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'surové',
            self::Cooked => 'uvarené / tepelne upravené',
            self::Dry => 'suché',
            self::Canned => 'konzervované',
            self::Unknown => 'neurčené',
        };
    }

    /**
     * Best guess from a provider description ("Rice, white, ..., cooked without salt" → cooked). Only a default
     * for a new record; the curator can correct it.
     */
    public static function fromDescription(string $description): self
    {
        $lower = mb_strtolower($description);

        return match (true) {
            str_contains($lower, 'cooked'), str_contains($lower, 'roasted'), str_contains($lower, 'boiled'), str_contains($lower, 'baked'), str_contains($lower, 'fried') => self::Cooked,
            str_contains($lower, 'canned') => self::Canned,
            str_contains($lower, ', raw'), str_ends_with($lower, ' raw'), str_contains($lower, 'fresh') => self::Raw,
            str_contains($lower, ', dry'), str_contains($lower, 'dried'), str_contains($lower, 'flour'), str_contains($lower, 'spices,'), str_contains($lower, 'uncooked') => self::Dry,
            default => self::Unknown,
        };
    }
}
