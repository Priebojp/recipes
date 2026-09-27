<?php

namespace App\Enums;

enum LegalDocumentState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('návrh'),
            self::Published => __('publikovaná'),
            self::Archived => __('archivovaná'),
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Published => 'green',
            self::Archived => 'zinc',
        };
    }
}
