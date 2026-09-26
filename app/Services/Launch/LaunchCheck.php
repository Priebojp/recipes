<?php

namespace App\Services\Launch;

use App\Enums\LaunchCheckStatus;

/**
 * One line of the launch checklist: what was checked, the verdict and what to do about it.
 */
final readonly class LaunchCheck
{
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public LaunchCheckStatus $status,
        public ?string $detail = null,
        public ?string $hint = null,
    ) {}

    public function isFail(): bool
    {
        return $this->status === LaunchCheckStatus::Fail;
    }

    /** @return array{key: string, group: string, label: string, status: string, detail: string|null, hint: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
