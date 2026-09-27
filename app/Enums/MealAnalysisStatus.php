<?php

namespace App\Enums;

/**
 * Where one photo analysis stands. Uploaded → analyzing (AI job active) → needs_review (proposal delivered) →
 * confirmed (person checked the components). Unusable = the AI could not recognise food, discarded = thrown away.
 */
enum MealAnalysisStatus: string
{
    case Uploaded = 'uploaded';
    case Analyzing = 'analyzing';
    case NeedsReview = 'needs_review';
    case Confirmed = 'confirmed';
    case Unusable = 'unusable';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => __('nahraté'),
            self::Analyzing => __('analyzuje sa'),
            self::NeedsReview => __('na kontrolu'),
            self::Confirmed => __('potvrdené'),
            self::Unusable => __('nedá sa určiť'),
            self::Discarded => __('zahodené'),
        };
    }

    /** Still a draft a person may finish (or the cleanup may delete after the draft TTL). */
    public function isDraft(): bool
    {
        return in_array($this, [self::Uploaded, self::Analyzing, self::NeedsReview, self::Unusable], true);
    }
}
