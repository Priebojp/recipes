<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Planned = 'planned';
    case Cooked = 'cooked';
    case Cancelled = 'cancelled';
}
