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
            self::Trial => __('skúšobné'),
            self::Subscription => __('predplatné'),
            self::Addon => __('dokúpený balík'),
            self::Compensation => __('kompenzácia'),
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
