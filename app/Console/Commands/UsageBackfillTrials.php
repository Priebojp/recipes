<?php

namespace App\Console\Commands;

use App\Services\Usage\TrialGrants;
use Illuminate\Console\Command;

/**
 * Rollout of the usage ledger: gives existing verified accounts their one-time trial. Safe to re-run – a household
 * or owner that already has a trial is skipped, and past AI jobs are never counted as consumption.
 */
class UsageBackfillTrials extends Command
{
    protected $signature = 'app:usage-backfill-trials';

    protected $description = 'Idempotentne udelí skúšobné AI použitia existujúcim domácnostiam s overeným vlastníkom.';

    public function handle(TrialGrants $trials): int
    {
        $result = $trials->backfill();

        $this->info("Prezretých domácností: {$result['households']}, nových skúšobných grantov: {$result['granted']}.");

        return self::SUCCESS;
    }
}
