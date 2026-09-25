<?php

namespace App\Services\Selection;

final class ScoredCandidate
{
    /**
     * @param  list<string>  $reasons  human readable explanation built from the applied rules
     * @param  array<string, float|int|null>  $factors
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly float $weight,
        public readonly array $reasons,
        public readonly array $factors,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'weight' => $this->weight,
            'reasons' => $this->reasons,
            'factors' => $this->factors,
        ];
    }
}
