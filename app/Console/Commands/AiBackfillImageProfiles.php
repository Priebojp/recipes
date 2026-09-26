<?php

namespace App\Console\Commands;

use App\Enums\AiJobKind;
use App\Models\AiJob;
use App\Services\Ai\ImageProfile;
use Illuminate\Console\Command;

/**
 * Rollout of v2.1 stage 8: image jobs created before profiles had a code are Standard (the only offer of v2).
 * Idempotent; stored quality/size values are kept untouched.
 */
class AiBackfillImageProfiles extends Command
{
    protected $signature = 'app:ai-backfill-image-profiles {--dry-run : Iba spočítať, nič nezapisovať}';

    protected $description = 'Doplní kód profilu image_standard_v1 obrázkovým úlohám bez kódu (etapa 8, idempotentné).';

    public function handle(): int
    {
        $updated = 0;
        $seen = 0;

        AiJob::query()->where('kind', AiJobKind::Image)->orderBy('id')->chunkById(200, function ($jobs) use (&$updated, &$seen) {
            foreach ($jobs as $job) {
                $seen++;
                $profile = $job->profile ?? [];
                if (isset($profile['code'])) {
                    continue;
                }
                $updated++;
                if (! $this->option('dry-run')) {
                    $job->update(['profile' => [...ImageProfile::LEGACY->snapshot(), ...$profile, 'code' => ImageProfile::LEGACY->value]]);
                }
            }
        });

        $this->components->info(($this->option('dry-run') ? 'Dry run: ' : '')."{$updated} z {$seen} obrázkových úloh bez kódu profilu → ".ImageProfile::LEGACY->value.'.');

        return self::SUCCESS;
    }
}
