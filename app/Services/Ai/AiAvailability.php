<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\Household;

/**
 * Tells the UI whether AI is configured and whether the household still has budget for another run.
 */
class AiAvailability
{
    public function __construct(private AiSettings $settings) {}

    public function textProvider(): string
    {
        return $this->settings->textProvider();
    }

    public function imageProvider(): string
    {
        return $this->settings->imageProvider();
    }

    public function textConfigured(): bool
    {
        return $this->providerHasKey($this->textProvider());
    }

    public function imageConfigured(): bool
    {
        return $this->providerHasKey($this->imageProvider());
    }

    /**
     * Global kill switch set by the administrator: stops new jobs, keeps all data.
     */
    public function enabled(): bool
    {
        return $this->settings->enabled();
    }

    /**
     * Returns null when a new job may start, otherwise the reason why not.
     */
    public function reasonUnavailable(Household $household, AiJobKind $kind): ?string
    {
        $configured = $kind === AiJobKind::Text ? $this->textConfigured() : $this->imageConfigured();
        if (! $configured) {
            return 'AI nie je nakonfigurované – chýba API kľúč poskytovateľa. Recept funguje bez AI.';
        }

        if (! $this->enabled()) {
            return 'AI funkcie sú dočasne nedostupné. Recepty môžeš ďalej upravovať ručne.';
        }

        $limit = $kind === AiJobKind::Text ? $this->settings->dailyTextLimit() : $this->settings->dailyImageLimit();
        $used = AiJob::query()
            ->where('household_id', $household->id)
            ->where('kind', $kind)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($used >= $limit) {
            return "Denný limit {$limit} AI úloh pre domácnosť je vyčerpaný. Skús to zajtra.";
        }

        $running = AiJob::query()
            ->where('household_id', $household->id)
            ->whereIn('status', [AiJobStatus::Queued, AiJobStatus::Running])
            ->count();

        if ($running >= $this->settings->maxConcurrentJobs()) {
            return 'Prebieha už maximálny počet AI úloh. Počkaj na ich dokončenie.';
        }

        return null;
    }

    private function providerHasKey(string $provider): bool
    {
        $config = config("ai.providers.{$provider}");
        if (! is_array($config)) {
            return false;
        }

        if (($config['driver'] ?? '') === 'ollama') {
            return true;
        }

        return filled($config['key'] ?? null);
    }
}
