<?php

namespace App\Console\Commands;

use App\Services\Privacy\AccountErasure;
use Illuminate\Console\Command;

/**
 * Run after a backup restore: completed erasure requests are the register of what must stay deleted.
 */
class PrivacyReapplyErasures extends Command
{
    protected $signature = 'app:privacy-reapply-erasures';

    protected $description = 'Re-apply completed erasure requests whose household content reappeared (e.g. after restoring a backup)';

    public function handle(AccountErasure $erasure): int
    {
        $households = $erasure->reapplyCompleted();

        if ($households === []) {
            $this->info('Všetky vybavené výmazy sú v poriadku; nič sa neobnovilo.');
        } else {
            $this->warn('Znovu vymazaný obsah domácností: '.implode(', ', $households).'.');
        }

        return self::SUCCESS;
    }
}
