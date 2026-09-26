<?php

namespace App\Services\Food;

/**
 * Units as people write them in recipes ("PL", "lyžica", "hrnček", "ks") mapped to one canonical code, and the
 * two families that convert without any per-food knowledge: mass (g, dkg, kg) and volume (ml, dl, l).
 * Everything else – and volume to mass – needs a confirmed FoodUnitConversion of that specific food.
 */
final class FoodUnit
{
    public const PIECE = 'ks';

    public const TABLESPOON = 'PL';

    public const TEASPOON = 'ČL';

    public const CUP = 'šálka';

    public const MILLILITRE = 'ml';

    /** Units an administrator may define a per-food gram weight for (ml = grams of one millilitre, i.e. density). */
    public const CONVERTIBLE = [self::PIECE, self::TABLESPOON, self::TEASPOON, self::CUP, self::MILLILITRE, 'strúčik', 'plátok', 'balenie', 'zväzok', 'štipka'];

    private const MASS_IN_GRAMS = ['g' => 1.0, 'dkg' => 10.0, 'kg' => 1000.0];

    private const VOLUME_IN_ML = ['ml' => 1.0, 'dl' => 100.0, 'l' => 1000.0];

    private const SYNONYMS = [
        'g' => ['g', 'gram', 'gramy', 'gramov', 'gr'],
        'dkg' => ['dkg', 'dag', 'deko'],
        'kg' => ['kg', 'kilo', 'kilogram'],
        'ml' => ['ml', 'mililiter', 'mililitre', 'mililitrov'],
        'dl' => ['dl', 'deci'],
        'l' => ['l', 'liter', 'litre', 'litrov', 'litr'],
        self::PIECE => ['ks', 'kus', 'kusy', 'kusov', 'kúsok', 'kúsky', 'kúskov', 'x'],
        self::TABLESPOON => ['pl', 'lyžica', 'lyžice', 'lyžíc', 'polievková lyžica', 'polievkové lyžice', 'lžíce', 'lžic', 'lžíc', 'lžíce', 'tbsp'],
        self::TEASPOON => ['čl', 'kl', 'lyžička', 'lyžičky', 'lyžičiek', 'kávová lyžička', 'čajová lyžička', 'lžička', 'lžičky', 'lžiček', 'tsp'],
        self::CUP => ['šálka', 'šálky', 'šálok', 'hrnček', 'hrnčeky', 'hrnčekov', 'hrnek', 'hrnky', 'hrnků', 'cup'],
        'strúčik' => ['strúčik', 'strúčiky', 'strúčikov', 'stroužek', 'stroužky', 'stroužků'],
        'plátok' => ['plátok', 'plátky', 'plátkov', 'plátek', 'plátků'],
        'balenie' => ['balenie', 'balenia', 'balení', 'balíček', 'balíčky', 'balíčkov', 'bal'],
        'zväzok' => ['zväzok', 'zväzky', 'zväzkov', 'svazek', 'svazky', 'svazků'],
        'štipka' => ['štipka', 'štipky', 'špetka', 'špetky'],
    ];

    /**
     * Canonical code of a written unit, or null when it is unknown. An empty unit next to a number ("2 vajcia")
     * means pieces.
     */
    public static function canonical(?string $unit): ?string
    {
        $value = mb_strtolower(trim((string) $unit));
        $value = rtrim($value, '.');

        if ($value === '') {
            return self::PIECE;
        }

        foreach (self::SYNONYMS as $canonical => $synonyms) {
            if (in_array($value, $synonyms, true)) {
                return $canonical;
            }
        }

        return null;
    }

    public static function isMass(?string $canonical): bool
    {
        return $canonical !== null && array_key_exists($canonical, self::MASS_IN_GRAMS);
    }

    public static function isVolume(?string $canonical): bool
    {
        return $canonical !== null && array_key_exists($canonical, self::VOLUME_IN_ML);
    }

    /**
     * Grams of an amount written in a mass unit; null for any other unit.
     */
    public static function massToGrams(float $amount, ?string $canonical): ?float
    {
        $factor = self::MASS_IN_GRAMS[$canonical ?? ''] ?? null;

        return $factor === null ? null : $amount * $factor;
    }

    /**
     * Millilitres of an amount written in a volume unit; null for any other unit.
     */
    public static function volumeToMillilitres(float $amount, ?string $canonical): ?float
    {
        $factor = self::VOLUME_IN_ML[$canonical ?? ''] ?? null;

        return $factor === null ? null : $amount * $factor;
    }

    /**
     * The conversion unit a per-food gram weight must exist for: volumes need the density row ("ml"), other
     * non-mass units need their own row. Mass units need none (null).
     */
    public static function conversionUnitFor(?string $canonical): ?string
    {
        if ($canonical === null || self::isMass($canonical)) {
            return null;
        }

        return self::isVolume($canonical) ? self::MILLILITRE : $canonical;
    }
}
