<?php

namespace App\Services\Selection;

final class ExcludedCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $rule,
        public readonly string $reason,
        public readonly bool $hard,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'rule' => $this->rule, 'reason' => $this->reason, 'hard' => $this->hard];
    }
}
