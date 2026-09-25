<?php

namespace App\Services\Selection;

/**
 * Numeric weights of the generator. Values live in config/recipes.php, not in a user-facing form.
 */
final class SelectionConfig
{
    /**
     * @param  array<string, float>  $preferenceScores
     * @param  list<array{0: int, 1: float}>  $recency
     */
    public function __construct(
        public readonly int $version,
        public readonly array $preferenceScores,
        public readonly float $groupMinWeight,
        public readonly float $groupAvgWeight,
        public readonly array $recency,
        public readonly float $recencyDefault,
        public readonly int $frequencyWindowDays,
        public readonly float $frequencyCoefficient,
        public readonly float $otherGroupBase,
        public readonly float $otherGroupSpan,
        public readonly float $plannedFactor,
        public readonly int $plannedWindowDays,
        public readonly float $minWeight,
        public readonly int $sessionTtlHours,
    ) {}

    /** @param  array<string, mixed>|null  $config */
    public static function fromConfig(?array $config = null): self
    {
        $c = $config ?? config('recipes.selection');

        return new self(
            version: (int) $c['config_version'],
            preferenceScores: $c['preference_scores'],
            groupMinWeight: (float) $c['group_min_weight'],
            groupAvgWeight: (float) $c['group_avg_weight'],
            recency: $c['recency'],
            recencyDefault: (float) $c['recency_default'],
            frequencyWindowDays: (int) $c['frequency_window_days'],
            frequencyCoefficient: (float) $c['frequency_coefficient'],
            otherGroupBase: (float) $c['other_group_base'],
            otherGroupSpan: (float) $c['other_group_span'],
            plannedFactor: (float) $c['planned_factor'],
            plannedWindowDays: (int) $c['planned_window_days'],
            minWeight: (float) $c['min_weight'],
            sessionTtlHours: (int) $c['session_ttl_hours'],
        );
    }

    public function scoreFor(?string $preference): float
    {
        return (float) ($this->preferenceScores[$preference ?? 'unrated'] ?? $this->preferenceScores['unrated']);
    }

    public function recencyFactor(?int $days): float
    {
        if ($days === null) {
            return $this->recencyDefault;
        }

        foreach ($this->recency as [$maxDays, $factor]) {
            if ($days <= $maxDays) {
                return (float) $factor;
            }
        }

        return $this->recencyDefault;
    }
}
