<?php

namespace App\Services\Selection;

final class SelectionFilters
{
    public function __construct(
        public readonly bool $includeUntyped = true,
        public readonly bool $onlyFavoritesOfAll = false,
        public readonly bool $allowDisliked = false,
        public readonly ?int $maxMinutes = null,
        public readonly bool $includeUnknownTime = false,
        public readonly ?int $noRepeatDays = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            includeUntyped: (bool) ($data['include_untyped'] ?? true),
            onlyFavoritesOfAll: (bool) ($data['only_favorites_of_all'] ?? false),
            allowDisliked: (bool) ($data['allow_disliked'] ?? false),
            maxMinutes: isset($data['max_minutes']) && $data['max_minutes'] !== '' ? (int) $data['max_minutes'] : null,
            includeUnknownTime: (bool) ($data['include_unknown_time'] ?? false),
            noRepeatDays: isset($data['no_repeat_days']) && $data['no_repeat_days'] !== '' ? (int) $data['no_repeat_days'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'include_untyped' => $this->includeUntyped,
            'only_favorites_of_all' => $this->onlyFavoritesOfAll,
            'allow_disliked' => $this->allowDisliked,
            'max_minutes' => $this->maxMinutes,
            'include_unknown_time' => $this->includeUnknownTime,
            'no_repeat_days' => $this->noRepeatDays,
        ];
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }
}
