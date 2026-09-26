<?php

namespace App\Enums;

/**
 * What one granted "use" buys. Text = one delivered text suggestion, ImageStandard = one delivered Standard image.
 */
enum UsageKind: string
{
    case Text = 'text';
    case ImageStandard = 'image_standard';

    public static function fromAiJobKind(AiJobKind $kind): self
    {
        return match ($kind) {
            AiJobKind::Text => self::Text,
            AiJobKind::Image => self::ImageStandard,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'textové operácie',
            self::ImageStandard => 'obrázky Standard',
        };
    }

    /** Singular form for "spotrebuje 1 …". */
    public function unitLabel(): string
    {
        return match ($this) {
            self::Text => 'textová operácia',
            self::ImageStandard => 'obrázok',
        };
    }
}
