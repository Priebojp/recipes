<?php

namespace App\Enums;

enum AiJobKind: string
{
    case Text = 'text';
    case Image = 'image';

    /** Photo → visible components (v2.1 stage 11). Text model with image input; billed as text tokens. */
    case MealAnalysis = 'meal_analysis';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text'),
            self::Image => __('Obrázok'),
            self::MealAnalysis => __('Analýza jedla'),
        };
    }

    /** Whether the provider call goes through the text (chat) endpoint rather than image generation. */
    public function usesTextProvider(): bool
    {
        return $this !== self::Image;
    }
}
