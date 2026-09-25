<?php

namespace App\Services;

use App\Models\IngredientLine;

/**
 * Pure calculation of ingredient amounts for a different number of servings.
 * Text amounts are never changed and the stored original is never modified.
 */
class ServingScaler
{
    public function __construct(private IngredientAmountParser $parser = new IngredientAmountParser) {}

    public function canScale(?int $baseServings): bool
    {
        return $baseServings !== null && $baseServings > 0;
    }

    /**
     * @param  iterable<IngredientLine>  $lines
     * @return list<array{name: string, amount: string|null, unit: string|null, note: string|null, scaled: bool}>
     */
    public function scale(iterable $lines, ?int $baseServings, ?int $targetServings): array
    {
        $ratio = ($this->canScale($baseServings) && $targetServings !== null && $targetServings > 0)
            ? $targetServings / $baseServings
            : null;

        $result = [];

        foreach ($lines as $line) {
            $amount = $line->text_amount;
            $scaled = false;

            if ($line->numeric_amount !== null) {
                $value = (float) $line->numeric_amount;

                if ($ratio !== null) {
                    $value = $value * $ratio;
                    $scaled = $ratio !== 1.0;
                }

                $amount = $this->parser->format($value);
            }

            $result[] = [
                'name' => $line->name,
                'amount' => $amount,
                'unit' => $line->unit,
                'note' => $line->note,
                'scaled' => $scaled,
            ];
        }

        return $result;
    }
}
