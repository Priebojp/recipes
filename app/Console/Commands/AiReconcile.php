<?php

namespace App\Console\Commands;

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Services\Ai\AiJobLifecycle;
use Illuminate\Console\Command;

/**
 * Jobs whose provider call ended ambiguously hold their reserved use. The SDK cannot recover a lost result, so the
 * operator's decision is: mark failed, return the use to the household, keep any external cost with the operator.
 */
class AiReconcile extends Command
{
    protected $signature = 'app:ai-reconcile
        {job? : ID AI úlohy v stave reconciling}
        {--fail : Označiť ako zlyhanú a uvoľniť rezervované použitie}
        {--all : S --fail spracovať všetky úlohy v stave reconciling}
        {--reason= : Dôvod rozhodnutia (ide do auditu)}';

    protected $description = 'Vypíše AI úlohy v stave reconciling; s --fail ich uzavrie a uvoľní rezervované použitie.';

    public function handle(AiJobLifecycle $lifecycle): int
    {
        $query = AiJob::query()->where('status', AiJobStatus::Reconciling)->orderBy('id');
        if ($this->argument('job')) {
            $query->whereKey((int) $this->argument('job'));
        }
        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->info('Žiadne úlohy v stave reconciling.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Domácnosť', 'Druh', 'Model', 'Skončila', 'Chyba'],
            $jobs->map(fn (AiJob $j) => [$j->id, $j->household_id, $j->kind->value, $j->model, $j->finished_at?->toDateTimeString(), mb_substr((string) $j->error, 0, 60)])->all(),
        );

        if (! $this->option('fail')) {
            return self::SUCCESS;
        }

        if (! $this->argument('job') && ! $this->option('all')) {
            $this->error('Na uzavretie zadaj ID úlohy alebo --all.');

            return self::INVALID;
        }

        $reason = trim((string) $this->option('reason')) ?: 'artisan app:ai-reconcile – výsledok sa nedal obnoviť';
        foreach ($jobs as $job) {
            $lifecycle->resolveAsFailed($job, null, $reason);
            $this->info("Úloha #{$job->id} označená ako zlyhaná, použitie uvoľnené.");
        }

        return self::SUCCESS;
    }
}
