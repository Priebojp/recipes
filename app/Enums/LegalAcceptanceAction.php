<?php

namespace App\Enums;

enum LegalAcceptanceAction: string
{
    case Registration = 'registration';
    case Checkout = 'checkout';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Registration => __('registrácia'),
            self::Checkout => __('objednávka'),
            self::Withdrawal => __('odstúpenie od zmluvy'),
        };
    }
}
