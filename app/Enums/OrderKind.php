<?php

namespace App\Enums;

enum OrderKind: string
{
    case Subscription = 'subscription';
    case Addon = 'addon';
}
