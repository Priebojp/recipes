<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use App\Services\Admin\Compensations;
use App\Services\Launch\LaunchReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Launch measurement (specification chapters 3 and 18): run N text and N image jobs on the real provider key against
 * an operator household and record what one delivered operation really costs. Jobs are ordinary ai_jobs (measured,
 * priced, ledgered against a dedicated compensation grant) so the admin AI dashboards see them too; only the daily
 * frequency caps are skipped. The summary is stored for the launch checklist.
 */
class AiMeasurement
{
    public const MONTHLY_TEXT_USES = 30;

    public const MONTHLY_IMAGE_USES = 5;

    public function __construct(
        private AiTextService $text,
        private AiImageService $images,
        private AiAvailability $availability,
        private AiSettings $settings,
        private Compensations $compensations,
        private AppSettings $appSettings,
        private AdminAuditor $audit,
    ) {}

    /**
     * @param  callable(AiJob): void|null  $progress  called after every finished job
     * @return array<string, mixed> the stored summary
     */
    public function run(Household $household, int $texts, int $images, string $scope = 'full', ?callable $progress = null, ?User $by = null): array
    {
        if ($texts < 0 || $images < 0 || $texts + $images === 0) {
            throw new InvalidArgumentException('Zadaj počet textových a/alebo obrázkových úloh.');
        }
        if ($texts > 0 && ! $this->availability->textConfigured()) {
            throw new InvalidArgumentException('Textový poskytovateľ nemá API kľúč – meranie by nič nezmeralo.');
        }
        if ($images > 0 && ! $this->availability->imageConfigured()) {
            throw new InvalidArgumentException('Obrázkový poskytovateľ nemá API kľúč – meranie by nič nezmeralo.');
        }
        if (! $this->settings->enabled()) {
            throw new InvalidArgumentException('AI je globálne vypnuté (kill switch); meranie sa nespustí.');
        }

        $recipes = $household->recipes()->whereNull('archived_at')->with(['ingredients', 'steps', 'mealTypes'])->orderBy('id')->get();
        if ($recipes->isEmpty()) {
            throw new InvalidArgumentException("Domácnosť {$household->id} nemá recepty; meranie potrebuje aspoň jeden.");
        }

        $run = CarbonImmutable::now()->format('Ymd-His').'-'.Str::lower(Str::random(4));

        // The uses are covered by an explicit, audited compensation grant so nothing is billed to a trial or a paid grant.
        if ($texts > 0) {
            $this->compensations->grantUses($household, UsageKind::Text, $texts, "Meranie AI nákladov {$run}", null, "measure:{$run}:text", $by);
        }
        if ($images > 0) {
            $this->compensations->grantUses($household, UsageKind::ImageStandard, $images, "Meranie AI nákladov {$run}", null, "measure:{$run}:image", $by);
        }

        $jobIds = [];
        for ($i = 0; $i < $texts; $i++) {
            $recipe = $recipes[$i % $recipes->count()];
            $job = $this->text->create($recipe, $by, $scope, fresh: true, rateLimits: false);
            $this->tag($job, $run);
            $this->text->run($job);
            $jobIds[] = $job->id;
            $progress !== null && $progress($job->fresh());
        }

        for ($i = 0; $i < $images; $i++) {
            $recipe = $recipes[$i % $recipes->count()];
            $job = $this->images->create($recipe, $by, $this->descriptionFor($recipe), 'auto', variant: true, rateLimits: false);
            $this->tag($job, $run);
            $this->images->run($job);
            $jobIds[] = $job->id;
            $progress !== null && $progress($job->fresh());
        }

        $summary = $this->summarize($run);
        $summary['household_id'] = $household->id;
        $this->appSettings->set(LaunchReadiness::MEASUREMENT_KEY, $summary, $by);
        $this->audit->record('ai.measurement.completed', $household, [], [
            'run' => $run,
            'text_jobs' => $summary['kinds']['text']['jobs'] ?? 0,
            'image_jobs' => $summary['kinds']['image']['jobs'] ?? 0,
            'total_cost_micro_usd' => $summary['total_cost_micro'],
        ], 'launch measurement', $by);

        return $summary;
    }

    /**
     * Aggregate the jobs of one run (also usable later: `app:ai-measure --report=<run>`).
     *
     * @return array<string, mixed>
     */
    public function summarize(string $run): array
    {
        $kinds = [];
        foreach (AiJobKind::cases() as $kind) {
            $jobs = AiJob::query()->where('input->measurement_run', $run)->where('kind', $kind)->get();
            if ($jobs->isEmpty()) {
                continue;
            }
            $ok = $jobs->where('status', AiJobStatus::Succeeded);
            $priced = $ok->whereNotNull('estimated_cost_micro_usd');
            $first = $jobs->first();

            $kinds[$kind->value] = [
                'jobs' => $jobs->count(),
                'succeeded' => $ok->count(),
                'failed' => $jobs->where('status', AiJobStatus::Failed)->count(),
                'unpriced' => $ok->count() - $priced->count(),
                'model' => $first->model,
                'provider' => $first->provider,
                'profile' => $first->profile,
                'total_cost_micro' => (int) $priced->sum('estimated_cost_micro_usd'),
                'avg_cost_micro' => $priced->isNotEmpty() ? (int) round($priced->avg('estimated_cost_micro_usd')) : null,
                'min_cost_micro' => $priced->isNotEmpty() ? (int) $priced->min('estimated_cost_micro_usd') : null,
                'max_cost_micro' => $priced->isNotEmpty() ? (int) $priced->max('estimated_cost_micro_usd') : null,
                'avg_duration_ms' => $ok->isNotEmpty() ? (int) round($ok->avg('duration_ms')) : null,
                'avg_input_tokens' => $ok->isNotEmpty() ? (int) round($ok->avg('input_tokens')) : null,
                'avg_cached_input_tokens' => $ok->isNotEmpty() ? (int) round($ok->avg('cached_input_tokens')) : null,
                'avg_output_tokens' => $ok->isNotEmpty() ? (int) round($ok->avg('output_tokens')) : null,
                'avg_reasoning_tokens' => $ok->isNotEmpty() ? (int) round($ok->avg('reasoning_tokens')) : null,
                'avg_image_output_tokens' => $ok->isNotEmpty() ? (int) round($ok->avg('image_output_tokens')) : null,
                'errors' => $jobs->where('status', AiJobStatus::Failed)->pluck('error')->filter()->map(fn ($e) => mb_substr((string) $e, 0, 120))->unique()->values()->all(),
            ];
        }

        $textAvg = $kinds['text']['avg_cost_micro'] ?? null;
        $imageAvg = $kinds['image']['avg_cost_micro'] ?? null;

        return [
            'run' => $run,
            'at' => CarbonImmutable::now()->toIso8601String(),
            'kinds' => $kinds,
            'total_cost_micro' => array_sum(array_column($kinds, 'total_cost_micro')),
            // What a fully used Plus month and the add-on packs cost in provider list prices (USD micro).
            'projection' => [
                'plus_month_micro' => $textAvg !== null && $imageAvg !== null ? self::MONTHLY_TEXT_USES * $textAvg + self::MONTHLY_IMAGE_USES * $imageAvg : null,
                'images_20_micro' => $imageAvg !== null ? 20 * $imageAvg : null,
                'text_100_micro' => $textAvg !== null ? 100 * $textAvg : null,
            ],
        ];
    }

    private function tag(AiJob $job, string $run): void
    {
        $job->update(['input' => [...($job->input ?? []), 'measurement_run' => $run]]);
    }

    /** The image prompt needs a short description; recipes without one use their title. */
    private function descriptionFor(Recipe $recipe): string
    {
        $description = trim((string) $recipe->description);

        return $description !== '' ? $description : (string) $recipe->title;
    }
}
