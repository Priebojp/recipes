<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use App\Support\Money;

/**
 * Runtime AI configuration: .env/config defaults overridable by the administrator (app_settings).
 * Jobs snapshot the profile at creation, so changing a setting never alters a queued job.
 */
class AiSettings
{
    /** "default" sends no reasoning parameter and lets the provider decide. */
    public const REASONING_EFFORTS = ['default', 'low', 'medium', 'high'];

    /** Quality tiers a cost rate may be priced for (the profiles in {@see ImageProfile} pick one of them). */
    public const IMAGE_QUALITIES = ['low', 'medium', 'high'];

    public const KEYS = [
        'enabled' => 'ai.enabled',
        'text_model' => 'ai.text_model',
        'text_reasoning_effort' => 'ai.text_reasoning_effort',
        'image_model' => 'ai.image_model',
        'image_profile' => 'ai.image_profile',
        'daily_text_limit' => 'ai.daily_text_limit',
        'daily_image_limit' => 'ai.daily_image_limit',
        'monthly_budget_micro_usd' => 'ai.monthly_budget_micro_usd',
    ];

    public function __construct(private AppSettings $settings, private AdminAuditor $audit) {}

    /** Global kill switch: false stops new jobs, keeps all data and shows a temporary-unavailability notice. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::KEYS['enabled'], config('recipes.ai.enabled', true));
    }

    public function textProvider(): string
    {
        return (string) config('recipes.ai.text_provider');
    }

    public function imageProvider(): string
    {
        return (string) config('recipes.ai.image_provider');
    }

    public function textModel(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::KEYS['text_model'], config('recipes.ai.text_model')));
    }

    public function textReasoningEffort(): string
    {
        $value = (string) $this->settings->get(self::KEYS['text_reasoning_effort'], config('recipes.ai.text_reasoning_effort', 'default'));

        return in_array($value, self::REASONING_EFFORTS, true) ? $value : 'default';
    }

    public function imageModel(): ?string
    {
        return $this->stringOrNull($this->settings->get(self::KEYS['image_model'], config('recipes.ai.image_model')));
    }

    /**
     * Default profile for new image jobs whose household has no entitlement deciding otherwise (v2.1 stage 8).
     * Runtime override stores the profile code; the .env default `RECIPES_AI_IMAGE_QUALITY` maps low → Economy,
     * medium → Standard. Only selectable profiles are accepted; anything else means Standard.
     */
    public function defaultImageProfile(): ImageProfile
    {
        $stored = $this->settings->get(self::KEYS['image_profile']);
        $profile = is_string($stored) ? ImageProfile::tryFrom($stored) : null;
        $profile ??= ImageProfile::fromQuality($this->stringOrNull(config('recipes.ai.image_quality', 'medium')));

        return in_array($profile, ImageProfile::selectable(), true) ? $profile : ImageProfile::StandardV1;
    }

    /** Quality tier of the default profile ("medium"). */
    public function imageQuality(): string
    {
        return $this->defaultImageProfile()->quality();
    }

    /** Pixel size of the default profile ("1024x1024"). */
    public function imagePixelSize(): string
    {
        return $this->defaultImageProfile()->pixelSize();
    }

    public function dailyTextLimit(): int
    {
        return max(0, (int) $this->settings->get(self::KEYS['daily_text_limit'], config('recipes.ai.daily_text_limit')));
    }

    public function dailyImageLimit(): int
    {
        return max(0, (int) $this->settings->get(self::KEYS['daily_image_limit'], config('recipes.ai.daily_image_limit')));
    }

    public function maxConcurrentJobs(): int
    {
        return (int) config('recipes.ai.max_concurrent_jobs');
    }

    public function timeoutSeconds(): int
    {
        return (int) config('recipes.ai.timeout_seconds');
    }

    /** Soft monthly budget alarm in micro-USD, null when not set. */
    public function monthlyBudgetMicroUsd(): ?int
    {
        $stored = $this->settings->get(self::KEYS['monthly_budget_micro_usd']);
        if ($stored !== null) {
            return (int) $stored > 0 ? (int) $stored : null;
        }

        $fromConfig = config('recipes.ai.monthly_budget_usd');

        return filled($fromConfig) ? Money::parseUsdToMicro((string) $fromConfig) : null;
    }

    /**
     * Snapshot stored on a text job.
     *
     * @return array{reasoning_effort: string}
     */
    public function textProfile(): array
    {
        return ['reasoning_effort' => $this->textReasoningEffort()];
    }

    /**
     * Snapshot of the default profile as stored on an image job ({@see ImageProfile::snapshot()}).
     *
     * @return array{code: string, quality: string, size: string, pixel_size: string, count: int}
     */
    public function imageProfile(): array
    {
        return $this->defaultImageProfile()->snapshot();
    }

    /**
     * Current values in the shape of the admin form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'text_model' => $this->textModel(),
            'text_reasoning_effort' => $this->textReasoningEffort(),
            'image_model' => $this->imageModel(),
            'image_profile' => $this->defaultImageProfile()->value,
            'daily_text_limit' => $this->dailyTextLimit(),
            'daily_image_limit' => $this->dailyImageLimit(),
            'monthly_budget_micro_usd' => $this->monthlyBudgetMicroUsd(),
        ];
    }

    /**
     * Config (.env) defaults, shown next to the form so the administrator sees what "reset" means.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'enabled' => (bool) config('recipes.ai.enabled', true),
            'text_model' => $this->stringOrNull(config('recipes.ai.text_model')),
            'text_reasoning_effort' => (string) config('recipes.ai.text_reasoning_effort', 'default'),
            'image_model' => $this->stringOrNull(config('recipes.ai.image_model')),
            'image_profile' => ImageProfile::fromQuality($this->stringOrNull(config('recipes.ai.image_quality', 'medium')))->value,
            'daily_text_limit' => (int) config('recipes.ai.daily_text_limit'),
            'daily_image_limit' => (int) config('recipes.ai.daily_image_limit'),
            'monthly_budget_micro_usd' => filled(config('recipes.ai.monthly_budget_usd')) ? Money::parseUsdToMicro((string) config('recipes.ai.monthly_budget_usd')) : null,
        ];
    }

    /**
     * Persist validated values from the admin form and audit the difference.
     *
     * @param  array<string, mixed>  $values  keys of self::KEYS
     */
    public function update(array $values, User $by, ?string $reason = null): void
    {
        $before = $this->toArray();

        $writes = [];
        foreach (self::KEYS as $field => $key) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if (is_string($value) && trim($value) === '') {
                $value = null;
            }
            $writes[$key] = $value;
        }

        $this->settings->setMany($writes, $by);

        $after = $this->toArray();
        if ($before !== $after) {
            $changedBefore = array_diff_assoc($before, $after);
            $changedAfter = array_intersect_key($after, $changedBefore);
            $this->audit->record('ai.settings.updated', 'ai_settings', $changedBefore, $changedAfter, $reason, $by);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return filled($value) ? (string) $value : null;
    }
}
