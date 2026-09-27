<?php

namespace App\Enums;

/**
 * What the model said about the photo (specification chapter 5). Only the first two are a delivered proposal that
 * costs a use; the other two release it and show "Nedokážem určiť" without any numbers.
 */
enum MealAnalysisAiStatus: string
{
    case Recognized = 'recognized';
    case NeedsClarification = 'needs_clarification';
    case NotFood = 'not_food';
    case Unusable = 'unusable';

    /** A proposal was delivered: the reserved use is consumed. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Recognized, self::NeedsClarification], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Recognized => __('rozpoznané'),
            self::NeedsClarification => __('potrebuje doplnenie'),
            self::NotFood => __('na fotke nie je jedlo'),
            self::Unusable => __('fotka sa nedá vyhodnotiť'),
        };
    }
}
