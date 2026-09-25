<?php

namespace App\Enums;

enum PersonKind: string
{
    case Member = 'member';
    case Guest = 'guest';
}
