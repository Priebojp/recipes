<?php

namespace App\Enums;

enum PlanMode: string
{
    case Date = 'date';
    case Week = 'week';
    case Someday = 'someday';
}
