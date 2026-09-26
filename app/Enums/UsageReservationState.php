<?php

namespace App\Enums;

enum UsageReservationState: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';

    public function isSettled(): bool
    {
        return $this !== self::Reserved;
    }
}
