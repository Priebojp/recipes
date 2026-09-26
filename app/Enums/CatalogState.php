<?php

namespace App\Enums;

enum CatalogState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
