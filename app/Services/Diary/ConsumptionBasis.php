<?php

namespace App\Services\Diary;

use App\Enums\NutritionCompleteness;
use App\Models\ConsumptionNutritionSnapshot;

/**
 * What a diary entry is computed from, frozen at the moment of saving: the stored components of a recipe
 * calculation (stage 10) or a confirmed photo analysis (stage 11), or the values a person typed. A correction
 * of the portion is computed from the basis stored in the previous snapshot – never from the live recipe,
 * mapping or food database – so history cannot change under a person's feet.
 */
final readonly class ConsumptionBasis
{
    /**
     * @param  array<string, mixed>  $meta  what the source is (kind, ids, versions, when it was computed); stored as-is
     * @param  list<array<string, mixed>>  $components  stored components of the whole source, as the recipe or photo calculator wrote them
     * @param  array<string, float|null>|null  $totals  of the whole source; used only when there are no components to sum (manual entry, legacy run)
     * @param  list<array{name: string, reason: string}>  $missing
     * @param  list<string>  $assumptions
     * @param  int  $divisor  how many units the whole source makes (a recipe: its servings; a photo: 1)
     * @param  float|null  $unitGrams  edible weight of one unit as a person weighed it (recipe final weight ÷ servings); null = unknown
     */
    public function __construct(
        public array $meta,
        public array $components,
        public ?array $totals,
        public NutritionCompleteness $completeness,
        public array $missing,
        public array $assumptions,
        public int $divisor = 1,
        public ?float $unitGrams = null,
    ) {}

    /**
     * Rebuild the basis a snapshot was computed from, for a correction of the portion.
     */
    public static function fromSnapshot(ConsumptionNutritionSnapshot $snapshot): self
    {
        $basis = $snapshot->basis;

        return new self(
            meta: (array) ($basis['meta'] ?? []),
            components: array_values((array) ($basis['components'] ?? [])),
            totals: $basis['totals'] ?? null,
            completeness: NutritionCompleteness::from((string) ($basis['completeness'] ?? NutritionCompleteness::Partial->value)),
            missing: array_values((array) ($basis['missing'] ?? [])),
            assumptions: array_values((array) ($basis['assumptions'] ?? [])),
            divisor: max(1, (int) ($basis['divisor'] ?? 1)),
            unitGrams: isset($basis['unit_grams']) ? (float) $basis['unit_grams'] : null,
        );
    }

    /**
     * @return array<string, mixed> the form stored in a snapshot
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'components' => $this->components,
            'totals' => $this->totals,
            'completeness' => $this->completeness->value,
            'missing' => $this->missing,
            'assumptions' => $this->assumptions,
            'divisor' => $this->divisor,
            'unit_grams' => $this->unitGrams,
        ];
    }

    /** Components that took part in the source sum. */
    public function hasIncludedComponents(): bool
    {
        foreach ($this->components as $component) {
            if (($component['included'] ?? false) && ($component['included_grams'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
