<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\Household;
use App\Services\Usage\UsageBalance;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageProvisioner;

/**
 * Tells the UI whether AI is configured and whether the household still has budget for another run.
 */
class AiAvailability
{
    public function __construct(private AiSettings $settings, private UsageLedger $ledger, private UsageProvisioner $provisioner) {}

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

        if ($household->isBlocked()) {
            return 'AI funkcie sú pre túto domácnosť pozastavené. Recepty môžeš ďalej upravovať ručne; ozvi sa podpore.';
        }

        // The ledger decides whether the household has a use left; the trial and the current monthly grant of a paid
        // period are opened lazily here (idempotent), so nothing depends on the scheduler having run.
        if ($this->ledger->enforced()) {
            $this->provisioner->ensureFor($household);
            if ($this->ledger->available($household, UsageKind::fromAiJobKind($kind)) < 1) {
                return $this->ledger->exhaustedMessage(UsageKind::fromAiJobKind($kind));
            }
        }

        // Daily limits remain as a frequency cap (abuse protection), not as the paid quota.
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

    /**
     * Uses left for the household, or null when the ledger is not enforced (nothing to show).
     */
    public function balance(Household $household, AiJobKind $kind): ?UsageBalance
    {
        if (! $this->ledger->enforced()) {
            return null;
        }

        $this->provisioner->ensureFor($household);

        return $this->ledger->balance($household, UsageKind::fromAiJobKind($kind));
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
