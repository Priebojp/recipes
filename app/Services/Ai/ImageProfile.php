<?php

namespace App\Services\Ai;

use App\Enums\UsageKind;

/**
 * Versioned image generation profiles (v2.1 supplement, chapter 3). The server – never the client – decides the
 * model tier: a profile fixes quality, size and count, and it is snapshotted on the job together with its code so a
 * later change of the default never alters a queued job. A new tier or size is a new code, not an edited one.
 */
enum ImageProfile: string
{
    case EconomyV1 = 'image_economy_v1';
    case StandardV1 = 'image_standard_v1';
    case HighV1 = 'image_high_v1';

    /** The profile an old job without a code was created with (v2 offered Standard only). */
    public const LEGACY = self::StandardV1;

    /** Profiles an administrator may pick as the default for new free/trial uses; High is not exposed. */
    public static function selectable(): array
    {
        return [self::EconomyV1, self::StandardV1];
    }

    /** Env default `RECIPES_AI_IMAGE_QUALITY` (low|medium|high) → profile; unknown values fall back to Standard. */
    public static function fromQuality(?string $quality): self
    {
        return match ($quality) {
            'low' => self::EconomyV1,
            'high' => self::HighV1,
            default => self::StandardV1,
        };
    }

    /**
     * Profile of a stored job snapshot. Jobs created before v2.1 carry no code and are read as Standard – that is the
     * grant they consumed.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function fromSnapshot(?array $snapshot): self
    {
        $code = $snapshot['code'] ?? null;

        return (is_string($code) ? self::tryFrom($code) : null) ?? self::LEGACY;
    }

    /** The first selectable profile that pays with the given kind of use. */
    public static function forUsageKind(UsageKind $kind): ?self
    {
        foreach (self::selectable() as $profile) {
            if ($profile->usageKind() === $kind) {
                return $profile;
            }
        }

        return null;
    }

    public function quality(): string
    {
        return match ($this) {
            self::EconomyV1 => 'low',
            self::StandardV1 => 'medium',
            self::HighV1 => 'high',
        };
    }

    /** Aspect ratio understood by the SDK. */
    public function size(): string
    {
        return '1:1';
    }

    /** Pixel size used by the provider and by the cost rates. */
    public function pixelSize(): string
    {
        return '1024x1024';
    }

    public function count(): int
    {
        return 1;
    }

    /**
     * Which kind of use the profile spends. Economy has its own kind so a Standard entitlement is never silently
     * spent on a low image; High is not sold and rides on the Standard entitlement (never a downgrade).
     */
    public function usageKind(): UsageKind
    {
        return match ($this) {
            self::EconomyV1 => UsageKind::ImageEconomy,
            self::StandardV1, self::HighV1 => UsageKind::ImageStandard,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EconomyV1 => 'Economy',
            self::StandardV1 => 'Standard',
            self::HighV1 => 'High',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EconomyV1 => 'low · 1024 × 1024 · kandidát pre bežné receptové karty',
            self::StandardV1 => 'medium · 1024 × 1024 · pôvodný návrh v2, spotrebúva nárok Standard',
            self::HighV1 => 'high · 1024 × 1024 · len budúce rozšírenie, nevystavuje sa',
        };
    }

    /**
     * Snapshot stored on an image job (`ai_jobs.profile`).
     *
     * @return array{code: string, quality: string, size: string, pixel_size: string, count: int}
     */
    public function snapshot(): array
    {
        return [
            'code' => $this->value,
            'quality' => $this->quality(),
            'size' => $this->size(),
            'pixel_size' => $this->pixelSize(),
            'count' => $this->count(),
        ];
    }
}
