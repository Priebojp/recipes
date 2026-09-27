<?php

namespace App\Console\Commands;

use App\Enums\MealAnalysisStatus;
use App\Models\MealAnalysis;
use App\Services\Ai\MealAnalysisService;
use Illuminate\Console\Command;

/**
 * Retention of photo analyses (v2.1 stage 11, specification chapter 10): working photos are deleted once their TTL
 * after the analysis finished passes (unless the person kept them with the record), and unfinished proposals are
 * deleted after the draft TTL. Confirmed records without a kept photo stay – only the photo file goes.
 */
class MealAnalysisCleanup extends Command
{
    protected $signature = 'app:meal-analysis-cleanup {--dry-run : Iba vypísať, čo by sa zmazalo}';

    protected $description = 'Zmaže pracovné fotky analýz jedla po TTL a nedokončené návrhy po lehote na dokončenie.';

    public function handle(MealAnalysisService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $photos = MealAnalysis::query()
            ->whereNull('photo_removed_at')
            ->whereNotNull('photo_retain_until')
            ->where('photo_retain_until', '<=', $now)
            ->get();
        foreach ($photos as $analysis) {
            if (! $dryRun) {
                $service->removePhoto($analysis);
            }
        }

        $drafts = MealAnalysis::query()
            ->whereIn('status', array_map(fn (MealAnalysisStatus $s) => $s->value, array_filter(MealAnalysisStatus::cases(), fn (MealAnalysisStatus $s) => $s->isDraft() || $s === MealAnalysisStatus::Discarded)))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();
        foreach ($drafts as $analysis) {
            if (! $dryRun) {
                // Model delete: the media library removes the photo file with the row.
                $analysis->delete();
            }
        }

        $this->components->info(($dryRun ? '[dry-run] ' : '')."Zmazané fotky: {$photos->count()}, zmazané nedokončené návrhy: {$drafts->count()}.");

        return self::SUCCESS;
    }
}
