<?php

namespace App\Enums;

enum UsageGrantSource: string
{
    case Trial = 'trial';
    case Subscription = 'subscription';
    case Addon = 'addon';
    case Compensation = 'compensation';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'skúšobné',
            self::Subscription => 'predplatné',
            self::Addon => 'dokúpený balík',
            self::Compensation => 'kompenzácia',
        };
    }

    /**
     * Included uses (trial, monthly) expire and are shown as "18/30"; purchased ones are listed separately.
     */
    public function isIncluded(): bool
    {
        return in_array($this, [self::Trial, self::Subscription], true);
    }
}
