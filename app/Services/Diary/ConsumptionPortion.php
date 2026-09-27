<?php

namespace App\Services\Diary;

use App\Enums\PortionMode;

/**
 * How much of one unit was eaten. A unit is one serving of a recipe or the whole plate of a photo analysis;
 * for a manual entry the typed values already describe what was eaten, so its unit is that.
 */
final readonly class ConsumptionPortion
{
    /**
     * @param  float|null  $fraction  0..1 (or more, a second helping) of the unit – fraction mode
     * @param  float|null  $grams  grams eaten – grams mode
     * @param  array<int, float>  $componentShares  component index => share eaten (0..1) – per-component mode; a missing index counts as all of it
     */
    private function __construct(
        public PortionMode $mode,
        public ?float $fraction = null,
        public ?float $grams = null,
        public array $componentShares = [],
    ) {}

    public static function fraction(float $fraction): self
    {
        return new self(PortionMode::Fraction, fraction: $fraction);
    }

    public static function grams(float $grams): self
    {
        return new self(PortionMode::Grams, grams: $grams);
    }

    /** @param  array<int, float>  $shares */
    public static function perComponent(array $shares): self
    {
        $clean = [];
        foreach ($shares as $index => $share) {
            $clean[(int) $index] = max(0.0, min(1.0, (float) $share));
        }

        return new self(PortionMode::PerComponent, componentShares: $clean);
    }

    public function isWhole(): bool
    {
        return $this->mode === PortionMode::Fraction && $this->fraction !== null && abs($this->fraction - 1.0) < 0.00001;
    }
}
