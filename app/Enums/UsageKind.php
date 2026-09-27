<?php

namespace App\Enums;

/**
 * What one granted "use" buys. Text = one delivered text suggestion, ImageStandard = one delivered Standard image,
 * ImageEconomy = one delivered Economy image (v2.1 stage 8 – separate so a Standard entitlement is never spent on low).
 */
enum UsageKind: string
{
    case Text = 'text';
    case ImageStandard = 'image_standard';
    case ImageEconomy = 'image_economy';

    /** The job kind whose daily frequency cap applies to this use. */
    public function aiJobKind(): AiJobKind
    {
        return match ($this) {
            self::Text => AiJobKind::Text,
            self::ImageStandard, self::ImageEconomy => AiJobKind::Image,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => __('textové operácie'),
            self::ImageStandard => __('obrázky Standard'),
            self::ImageEconomy => __('obrázky Economy'),
        };
    }

    /** Singular form for "spotrebuje 1 …". */
    public function unitLabel(): string
    {
        return match ($this) {
            self::Text => __('textová operácia'),
            self::ImageStandard => __('obrázok Standard'),
            self::ImageEconomy => __('obrázok Economy'),
        };
    }
}
