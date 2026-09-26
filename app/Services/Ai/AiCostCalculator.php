<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Models\AiCostRate;
use App\Models\AiJob;
use Carbon\CarbonInterface;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Estimates the provider cost of one AI job from its measured usage and the versioned list price.
 * Integer micro-USD math only; the provider's invoice stays authoritative.
 */
class AiCostCalculator
{
    /**
     * The list price valid for a job at the given time (latest effective_from that is not in the future).
     */
    public function rateFor(string $provider, ?string $model, string $modality, ?string $quality, ?string $pixelSize, CarbonInterface $at): ?AiCostRate
    {
        if ($model === null || $model === '') {
            return null;
        }

        $query = AiCostRate::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('modality', $modality)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if ($modality === AiCostRate::MODALITY_IMAGE) {
            $query->where(fn ($q) => $q->whereNull('quality')->orWhere('quality', $quality));
            $query->where(fn ($q) => $q->whereNull('size')->orWhere('size', $pixelSize));
            // Prefer the most specific row (quality and size set) over a generic one.
            $query->reorder()->orderByRaw('(case when quality is null then 1 else 0 end) + (case when size is null then 1 else 0 end)')
                ->orderByDesc('effective_from')->orderByDesc('id');
        }

        return $query->first();
    }

    public function rateForJob(AiJob $job, ?CarbonInterface $at = null): ?AiCostRate
    {
        $profile = $job->profile ?? [];

        return $this->rateFor(
            (string) $job->provider,
            $job->model,
            $job->kind === AiJobKind::Image ? AiCostRate::MODALITY_IMAGE : AiCostRate::MODALITY_TEXT,
            $profile['quality'] ?? null,
            $profile['pixel_size'] ?? null,
            $at ?? $job->finished_at ?? $job->created_at ?? now(),
        );
    }

    /**
     * Text: uncached input × input price + cached input × cached price + output (incl. reasoning) × output price.
     */
    public function estimateText(AiCostRate $rate, int $inputTokens, int $outputTokens, ?int $cachedInputTokens = null): int
    {
        $cached = min(max(0, (int) $cachedInputTokens), max(0, $inputTokens));
        $uncached = max(0, $inputTokens) - $cached;

        return self::perMillion($uncached, $rate->input_per_million)
            + self::perMillion($cached, $rate->cached_input_per_million ?? $rate->input_per_million)
            + self::perMillion(max(0, $outputTokens), $rate->output_per_million);
    }

    /**
     * Image: token-based when the provider reports image output tokens and the rate prices them,
     * otherwise the flat per-image estimate for the quality/size tier; prompt input tokens are added when priced.
     */
    public function estimateImage(AiCostRate $rate, int $images, int $inputTokens = 0, ?int $imageOutputTokens = null): int
    {
        $output = $imageOutputTokens !== null && $rate->output_per_million !== null
            ? self::perMillion($imageOutputTokens, $rate->output_per_million)
            : max(0, $images) * (int) ($rate->per_unit ?? 0);

        return $output + self::perMillion(max(0, $inputTokens), $rate->input_per_million);
    }

    /**
     * Usage columns + estimated cost for a finished job. Missing rate => usage stored, cost left null ("unpriced").
     *
     * @return array<string, int|null>
     */
    public function attributesFor(AiJob $job, ?Usage $usage, int $imagesDelivered = 0): array
    {
        $attributes = [
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'cached_input_tokens' => $usage instanceof TextUsage ? $usage->cacheReadInputTokens : null,
            'reasoning_tokens' => $usage instanceof TextUsage ? $usage->reasoningTokens : null,
            'image_output_tokens' => $usage instanceof ImageUsage ? $usage->imageOutputTokens : null,
            'estimated_cost_micro_usd' => null,
            'cost_rate_id' => null,
        ];

        $rate = $this->rateForJob($job, now());
        if ($rate === null) {
            return $attributes;
        }

        $attributes['cost_rate_id'] = $rate->id;
        $attributes['estimated_cost_micro_usd'] = $job->kind === AiJobKind::Image
            ? $this->estimateImage($rate, $imagesDelivered, $usage?->inputTokens ?? 0, $attributes['image_output_tokens'])
            : $this->estimateText($rate, $usage?->inputTokens ?? 0, $usage?->outputTokens ?? 0, $attributes['cached_input_tokens']);

        return $attributes;
    }

    /**
     * Expected cost of one image with the default profile, for the admin form ("what does one Standard image cost").
     */
    public function projectedImageCost(AiSettings $settings): ?int
    {
        return $this->projectedProfileCost($settings->defaultImageProfile(), $settings);
    }

    /**
     * Expected list-price cost of one image of the given profile with the configured provider/model, null when the
     * cost table has no matching rate.
     */
    public function projectedProfileCost(ImageProfile $profile, AiSettings $settings, ?string $model = null): ?int
    {
        $rate = $this->rateFor($settings->imageProvider(), $model ?? $settings->imageModel(), AiCostRate::MODALITY_IMAGE, $profile->quality(), $profile->pixelSize(), now());

        return $rate === null ? null : $this->estimateImage($rate, 1);
    }

    /**
     * Cost of tokens at a micro-USD-per-million price, rounded half up to a whole micro-USD.
     */
    public static function perMillion(int $tokens, ?int $microPerMillion): int
    {
        if ($tokens <= 0 || $microPerMillion === null || $microPerMillion <= 0) {
            return 0;
        }

        return intdiv($tokens * $microPerMillion + 500_000, 1_000_000);
    }
}
