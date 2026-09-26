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
            self::Registration => 'registrácia',
            self::Checkout => 'objednávka',
            self::Withdrawal => 'odstúpenie od zmluvy',
        };
    }
}
