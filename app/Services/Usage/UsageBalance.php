<?php

namespace App\Services\Usage;

use App\Enums\UsageKind;
use Carbon\CarbonInterface;

/**
 * What the household can spend right now for one kind of use, split the way the UI shows it:
 * "Skúšobné/Mesačné: 18/30, obnoví sa …" and separately "Dokúpené: 12".
 */
final readonly class UsageBalance
{
    public function __construct(
        public UsageKind $kind,
        public int $includedAvailable,
        public int $includedTotal,
        public ?CarbonInterface $includedExpiresAt,
        public ?string $includedSourceLabel,
        public int $purchasedAvailable,
    ) {}

    public function available(): int
    {
        return $this->includedAvailable + $this->purchasedAvailable;
    }

    public function isExhausted(): bool
    {
        return $this->available() < 1;
    }
}
