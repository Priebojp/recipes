<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\MealAnalysis;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use App\Services\Admin\Compensations;
use App\Services\Launch\LaunchReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        private MealAnalysisService $meals,
        private AiAvailability $availability,
        private AiSettings $settings,
        private Compensations $compensations,
        private AppSettings $appSettings,
        private AdminAuditor $audit,
    ) {}

    /**
     * @param  callable(AiJob): void|null  $progress  called after every finished job
     * @param  int  $mealAnalyses  photo analyses run on the operator's own fixtures (v2.1 stage 11), never on people's photos
     * @param  string|null  $fixturesPath  directory with the meal photos; default tests/fixtures/meals
     * @return array<string, mixed> the stored summary
     */
    public function run(Household $household, int $texts, int $images, string $scope = 'full', ?callable $progress = null, ?User $by = null, int $mealAnalyses = 0, ?string $fixturesPath = null): array
    {
        if ($texts < 0 || $images < 0 || $mealAnalyses < 0 || $texts + $images + $mealAnalyses === 0) {
            throw new InvalidArgumentException('Zadaj počet textových, obrázkových úloh a/alebo analýz jedla.');
        }
        if (($texts > 0 || $mealAnalyses > 0) && ! $this->availability->textConfigured()) {
            throw new InvalidArgumentException('Textový poskytovateľ nemá API kľúč – meranie by nič nezmeralo.');
        }
        if ($images > 0 && ! $this->availability->imageConfigured()) {
            throw new InvalidArgumentException('Obrázkový poskytovateľ nemá API kľúč – meranie by nič nezmeralo.');
        }
        if (! $this->settings->enabled()) {
            throw new InvalidArgumentException('AI je globálne vypnuté (kill switch); meranie sa nespustí.');
        }

        $recipes = $household->recipes()->whereNull('archived_at')->with(['ingredients', 'steps', 'mealTypes'])->orderBy('id')->get();
        if ($recipes->isEmpty() && $texts + $images > 0) {
            throw new InvalidArgumentException("Domácnosť {$household->id} nemá recepty; meranie potrebuje aspoň jeden.");
        }

        $fixtures = [];
        $owner = null;
        if ($mealAnalyses > 0) {
            $fixtures = self::mealFixtures($fixturesPath);
            if ($fixtures === []) {
                throw new InvalidArgumentException('Priečinok s testovacími fotkami jedál ('.($fixturesPath ?? self::defaultFixturesPath()).') je prázdny – nahraj vlastné fotky (JPG/PNG/WebP), nie fotky používateľov.');
            }
            $owner = $by ?? $household->owner;
            if (! $owner instanceof User) {
                throw new InvalidArgumentException('Analýzy jedla potrebujú používateľa – vlastníka domácnosti alebo --user.');
            }
        }

        $run = CarbonImmutable::now()->format('Ymd-His').'-'.Str::lower(Str::random(4));

        // The uses are covered by an explicit, audited compensation grant so nothing is billed to a trial or a paid grant.
        if ($texts > 0) {
            $this->compensations->grantUses($household, UsageKind::Text, $texts, "Meranie AI nákladov {$run}", null, "measure:{$run}:text", $by);
        }
        if ($images > 0) {
            // The measured images run with the default profile, so the grant is of that profile's kind.
            $this->compensations->grantUses($household, $this->settings->defaultImageProfile()->usageKind(), $images, "Meranie AI nákladov {$run}", null, "measure:{$run}:image", $by);
        }
        if ($mealAnalyses > 0) {
            $this->compensations->grantUses($household, UsageKind::MealAnalysis, $mealAnalyses, "Meranie AI nákladov {$run}", null, "measure:{$run}:meal", $by);
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
            $job = $this->images->create($recipe, $by, $this->descriptionFor($recipe), 'auto', variant: true, rateLimits: false, profile: $this->settings->defaultImageProfile());
            $this->tag($job, $run);
            $this->images->run($job);
            $jobIds[] = $job->id;
            $progress !== null && $progress($job->fresh());
        }

        for ($i = 0; $i < $mealAnalyses; $i++) {
            $fixture = $fixtures[$i % count($fixtures)];
            $analysis = $this->meals->upload($owner, $household, $fixture, 'meranie '.basename($fixture));
            $job = $this->meals->analyze($analysis, rateLimits: false);
            $this->tag($job, $run);
            $this->meals->run($job);
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
            'meal_analysis_jobs' => $summary['kinds']['meal_analysis']['jobs'] ?? 0,
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

            if ($kind === AiJobKind::MealAnalysis) {
                $kinds[$kind->value] = [...$kinds[$kind->value], ...$this->mealAnalysisStatistics($jobs)];
            }
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

    /**
     * Per analysis (root job + its clarifications): cost median and p95, how the model answered and how much the
     * person corrected – the numbers the launch checklist asks for before any price promise.
     *
     * @param  Collection<int, AiJob>  $jobs
     * @return array<string, mixed>
     */
    private function mealAnalysisStatistics($jobs): array
    {
        $roots = $jobs->whereNull('parent_ai_job_id');
        $analysisIds = $roots->map(fn (AiJob $job) => $job->input['meal_analysis_id'] ?? null)->filter()->unique()->values();
        $analyses = MealAnalysis::query()->whereIn('id', $analysisIds)->withCount('items')->get()->keyBy('id');

        $costs = [];
        $durations = [];
        $outcomes = ['recognized' => 0, 'needs_clarification' => 0, 'not_food' => 0, 'unusable' => 0];
        $corrections = 0;
        $questions = 0;
        foreach ($roots as $root) {
            $family = $jobs->filter(fn (AiJob $j) => $j->id === $root->id || $j->parent_ai_job_id === $root->id);
            if ($family->whereNotNull('estimated_cost_micro_usd')->isNotEmpty()) {
                $costs[] = (int) $family->sum('estimated_cost_micro_usd');
            }
            if ($root->duration_ms !== null) {
                $durations[] = (int) $family->sum('duration_ms');
            }
            $status = $root->output['status'] ?? null;
            if ($status !== null && isset($outcomes[$status])) {
                $outcomes[$status]++;
            }
            $questions += count($root->output['questions'] ?? []);

            $analysis = $analyses->get($root->input['meal_analysis_id'] ?? 0);
            if ($analysis !== null) {
                $proposed = array_column((array) ($root->output['components'] ?? []), 'label');
                $current = $analysis->items()->pluck('label')->all();
                // Renamed, removed or added components: a rough count of what the person had to fix.
                $corrections += count(array_diff($proposed, $current)) + count(array_diff($current, $proposed)) + $analysis->clarification_count;
            }
        }

        $delivered = $outcomes['recognized'] + $outcomes['needs_clarification'];

        return [
            'analyses' => $roots->count(),
            'delivered' => $delivered,
            'success_rate' => $roots->isNotEmpty() ? round($delivered / $roots->count(), 3) : null,
            'outcomes' => $outcomes,
            'median_cost_micro' => self::percentile($costs, 0.5),
            'p95_cost_micro' => self::percentile($costs, 0.95),
            'median_duration_ms' => self::percentile($durations, 0.5),
            'p95_duration_ms' => self::percentile($durations, 0.95),
            'questions_asked' => $questions,
            'corrections' => $corrections,
            'clarifications' => $jobs->whereNotNull('parent_ai_job_id')->count(),
        ];
    }

    /** @param  list<int>  $values */
    private static function percentile(array $values, float $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil($p * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }

    /**
     * Photos the operator prepared for measuring (their own, never a person's upload).
     *
     * @return list<string>
     */
    public static function mealFixtures(?string $path = null): array
    {
        $dir = $path ?? self::defaultFixturesPath();
        if (! is_dir($dir)) {
            return [];
        }
        $files = array_filter(glob($dir.'/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [], 'is_file');
        sort($files);

        return $files;
    }

    public static function defaultFixturesPath(): string
    {
        return base_path('tests/fixtures/meals');
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
