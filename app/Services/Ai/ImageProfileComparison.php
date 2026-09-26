<?php

namespace App\Services\Ai;

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use App\Services\Admin\Compensations;
use App\Services\Launch\LaunchSignoffs;
use App\Services\RecipeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The low/medium decision experiment of the v2.1 supplement (chapter 3): ten dishes, two Economy and two Standard
 * images each with the same prompt, run on the real key against the operator's test household. The images are
 * ordinary ai_jobs (measured, priced, ledgered against dedicated compensation grants) stored in the recipes' cover
 * collection without ever being activated. The administrator – not the AI – rates each image; the decision is
 * recorded as the launch sign-off `image_profile` and is the input of stage 13. A small sample decides, it proves
 * nothing about general accuracy.
 */
class ImageProfileComparison
{
    /** @var list<array{title: string, description: string, side_requirement: string, included_side?: string}> */
    public const DISHES = [
        ['title' => 'Bryndzové halušky', 'description' => 'Zemiakové halušky s bryndzou a opraženou slaninkou', 'side_requirement' => 'complete'],
        ['title' => 'Kurací paprikáš', 'description' => 'Kuracie kúsky v smotanovej paprikovej omáčke, bez prílohy', 'side_requirement' => 'needs_side'],
        ['title' => 'Hovädzí guláš', 'description' => 'Hovädzie mäso dusené s cibuľou a mletou paprikou', 'side_requirement' => 'needs_side'],
        ['title' => 'Slepačia polievka', 'description' => 'Číra slepačia polievka s rezancami, mrkvou a petržlenom', 'side_requirement' => 'complete'],
        ['title' => 'Vyprážaný rezeň so zemiakovým šalátom', 'description' => 'Bravčový rezeň v strúhanke so zemiakovým šalátom', 'side_requirement' => 'complete', 'included_side' => 'zemiakový šalát'],
        ['title' => 'Hríbové rizoto', 'description' => 'Krémové rizoto s lesnými hríbmi a parmezánom', 'side_requirement' => 'complete'],
        ['title' => 'Zeleninový šalát', 'description' => 'Šalát z paradajok, uhoriek, papriky a cibule s olejom', 'side_requirement' => 'complete'],
        ['title' => 'Cestoviny s paradajkovou omáčkou', 'description' => 'Penne s paradajkovou omáčkou, bazalkou a parmezánom', 'side_requirement' => 'complete'],
        ['title' => 'Praženica', 'description' => 'Praženica z troch vajec s pažítkou a krajcom chleba', 'side_requirement' => 'complete'],
        ['title' => 'Jablkový koláč', 'description' => 'Kysnutý koláč s jablkami a škoricou, nakrájaný na kocky', 'side_requirement' => 'complete'],
    ];

    /** Images per dish and profile. */
    public const VARIANTS = 2;

    /** @var list<ImageProfile> */
    public const PROFILES = [ImageProfile::EconomyV1, ImageProfile::StandardV1];

    /** Internal acceptance criterion from the supplement: at least this many Economy images acceptable. */
    public const ECONOMY_ACCEPTABLE_MINIMUM = 18;

    public const SETTING_PREFIX = 'ai.image_comparison.';

    public const SIGNOFF_KEY = 'image_profile';

    public function __construct(
        private AiImageService $images,
        private AiAvailability $availability,
        private AiSettings $settings,
        private AiCostCalculator $costs,
        private Compensations $compensations,
        private RecipeService $recipes,
        private AppSettings $appSettings,
        private LaunchSignoffs $signoffs,
        private AdminAuditor $audit,
    ) {}

    /** Images one full run generates per profile. */
    public static function imagesPerProfile(): int
    {
        return count(self::DISHES) * self::VARIANTS;
    }

    /**
     * List-price estimate of a full run (USD micro), per profile and in total; null when a rate is missing.
     *
     * @return array{profiles: array<string, int|null>, total: int|null, model: string|null}
     */
    public function estimate(): array
    {
        $profiles = [];
        foreach (self::PROFILES as $profile) {
            $one = $this->costs->projectedProfileCost($profile, $this->settings);
            $profiles[$profile->value] = $one === null ? null : $one * self::imagesPerProfile();
        }

        return [
            'profiles' => $profiles,
            'total' => in_array(null, $profiles, true) ? null : array_sum($profiles),
            'model' => $this->settings->imageModel(),
        ];
    }

    /**
     * @param  callable(AiJob): void|null  $progress  called after every finished job
     * @return array<string, mixed> the stored run record
     */
    public function run(Household $household, ?User $by = null, ?callable $progress = null): array
    {
        if (! $this->availability->imageConfigured()) {
            throw new InvalidArgumentException('Obrázkový poskytovateľ nemá API kľúč – porovnanie by nič nevygenerovalo.');
        }
        if (! $this->settings->enabled()) {
            throw new InvalidArgumentException('AI je globálne vypnuté (kill switch); porovnanie sa nespustí.');
        }

        $run = 'cmp-'.CarbonImmutable::now()->format('Ymd-His').'-'.Str::lower(Str::random(4));

        // One audited compensation grant per kind of use: the comparison never spends a trial or a paid entitlement.
        foreach (self::PROFILES as $profile) {
            $this->compensations->grantUses($household, $profile->usageKind(), self::imagesPerProfile(), "Porovnanie profilov obrázkov {$run}", null, "compare:{$run}:{$profile->value}", $by);
        }

        $jobIds = [];
        foreach (self::DISHES as $dish) {
            $recipe = $this->recipeFor($household, $by, $dish);
            foreach (self::PROFILES as $profile) {
                for ($variant = 1; $variant <= self::VARIANTS; $variant++) {
                    $job = $this->images->create($recipe, $by, $dish['description'], 'auto', variant: true, rateLimits: false, profile: $profile);
                    $job->update(['input' => [...($job->input ?? []), 'comparison_run' => $run, 'comparison_dish' => $dish['title'], 'comparison_variant' => $variant]]);
                    $this->images->run($job);
                    $jobIds[] = $job->id;
                    $progress !== null && $progress($job->fresh());
                }
            }
        }

        $record = [
            'run' => $run,
            'at' => CarbonImmutable::now()->toIso8601String(),
            'household_id' => $household->id,
            'by' => $by?->id,
            'model' => $this->settings->imageModel(),
            'estimate' => $this->estimate(),
            'job_ids' => $jobIds,
            'evaluations' => [],
            'decision' => null,
        ];
        $this->appSettings->set(self::SETTING_PREFIX.$run, $record, $by);

        $summary = $this->summarize($run);
        $this->audit->record('ai.image_comparison.completed', $household, [], [
            'run' => $run,
            'jobs' => count($jobIds),
            'total_cost_micro_usd' => $summary['total_cost_micro'],
        ], 'porovnanie profilov obrázkov low/medium', $by);

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function record(string $run): ?array
    {
        $value = $this->appSettings->get(self::SETTING_PREFIX.$run);

        return is_array($value) && ($value['run'] ?? null) === $run ? $value : null;
    }

    /**
     * Stored runs, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function runs(): Collection
    {
        return collect($this->appSettings->all())
            ->filter(fn ($value, $key) => is_string($key) && str_starts_with($key, self::SETTING_PREFIX) && is_array($value))
            ->values()
            ->sortByDesc('at')
            ->values();
    }

    /**
     * The run's jobs grouped for the side-by-side page plus per-profile totals.
     *
     * @return array{run: string, record: array<string, mixed>|null, dishes: list<array{title: string, jobs: array<string, list<AiJob>>}>, profiles: array<string, array{jobs: int, succeeded: int, failed: int, cost_micro: int, avg_cost_micro: int|null, evaluated: int, acceptable: int}>, total_cost_micro: int, criterion_met: bool|null}
     */
    public function summarize(string $run): array
    {
        $record = $this->record($run);
        $evaluations = $record['evaluations'] ?? [];
        $jobs = AiJob::query()->where('input->comparison_run', $run)->orderBy('id')->get();

        $dishes = [];
        foreach (self::DISHES as $dish) {
            $ofDish = $jobs->filter(fn (AiJob $j) => ($j->input['comparison_dish'] ?? null) === $dish['title']);
            if ($ofDish->isEmpty()) {
                continue;
            }
            $byProfile = [];
            foreach (self::PROFILES as $profile) {
                $byProfile[$profile->value] = $ofDish->filter(fn (AiJob $j) => ImageProfile::fromSnapshot($j->profile) === $profile)->values()->all();
            }
            $dishes[] = ['title' => $dish['title'], 'jobs' => $byProfile];
        }

        $profiles = [];
        foreach (self::PROFILES as $profile) {
            $ofProfile = $jobs->filter(fn (AiJob $j) => ImageProfile::fromSnapshot($j->profile) === $profile);
            $ok = $ofProfile->where('status', AiJobStatus::Succeeded);
            $priced = $ok->whereNotNull('estimated_cost_micro_usd');
            $rated = $ofProfile->filter(fn (AiJob $j) => isset($evaluations[$j->id]['acceptable']));
            $profiles[$profile->value] = [
                'jobs' => $ofProfile->count(),
                'succeeded' => $ok->count(),
                'failed' => $ofProfile->where('status', AiJobStatus::Failed)->count(),
                'cost_micro' => (int) $priced->sum('estimated_cost_micro_usd'),
                'avg_cost_micro' => $priced->isNotEmpty() ? (int) round($priced->avg('estimated_cost_micro_usd')) : null,
                'evaluated' => $rated->count(),
                'acceptable' => $rated->filter(fn (AiJob $j) => $evaluations[$j->id]['acceptable'] === true)->count(),
            ];
        }

        $economy = $profiles[ImageProfile::EconomyV1->value] ?? null;
        $criterion = $economy !== null && $economy['evaluated'] >= self::imagesPerProfile()
            ? $economy['acceptable'] >= self::ECONOMY_ACCEPTABLE_MINIMUM
            : null;

        return [
            'run' => $run,
            'record' => $record,
            'dishes' => $dishes,
            'profiles' => $profiles,
            'total_cost_micro' => (int) $jobs->sum('estimated_cost_micro_usd'),
            'criterion_met' => $criterion,
        ];
    }

    /**
     * The administrator's verdict on one image (null clears it). Stored on the run record, not on the job.
     */
    public function evaluate(string $run, int $jobId, ?bool $acceptable, string $note, User $by): void
    {
        $record = $this->record($run);
        if ($record === null || ! in_array($jobId, $record['job_ids'] ?? [], true)) {
            throw new InvalidArgumentException('Úloha nepatrí do tohto porovnania.');
        }

        $evaluations = $record['evaluations'] ?? [];
        if ($acceptable === null && trim($note) === '') {
            unset($evaluations[$jobId]);
        } else {
            $evaluations[$jobId] = ['acceptable' => $acceptable, 'note' => mb_substr(trim($note), 0, 500), 'by' => $by->id, 'at' => CarbonImmutable::now()->toIso8601String()];
        }

        $this->appSettings->set(self::SETTING_PREFIX.$run, [...$record, 'evaluations' => $evaluations], $by);
    }

    /**
     * Record the decision (which profile the new offer should use) as the launch sign-off, with the counts in the note.
     * The offer itself does not change here – that is stage 13.
     */
    public function decide(string $run, ImageProfile $profile, string $note, User $by): void
    {
        if (! in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Rozhodnúť možno len medzi porovnávanými profilmi.');
        }
        $record = $this->record($run);
        if ($record === null) {
            throw new InvalidArgumentException('Neznámy beh porovnania.');
        }

        $summary = $this->summarize($run);
        $counts = [];
        foreach (self::PROFILES as $p) {
            $s = $summary['profiles'][$p->value];
            $counts[] = "{$p->label()} prijateľných {$s['acceptable']}/{$s['evaluated']} (doručených {$s['succeeded']}/{$s['jobs']})";
        }
        $text = "Porovnanie {$run}: ".implode(', ', $counts).' · kritérium ≥ '.self::ECONOMY_ACCEPTABLE_MINIMUM.'/'.self::imagesPerProfile().' low '.match ($summary['criterion_met']) {
            true => 'splnené',
            false => 'nesplnené',
            null => 'nevyhodnotené (chýbajú hodnotenia)',
        }." · rozhodnutie: {$profile->label()} ({$profile->value})".(trim($note) !== '' ? ' · '.trim($note) : '');

        $this->appSettings->set(self::SETTING_PREFIX.$run, [...$record, 'decision' => ['profile' => $profile->value, 'by' => $by->id, 'at' => CarbonImmutable::now()->toIso8601String(), 'note' => mb_substr(trim($note), 0, 500)]], $by);
        $this->signoffs->confirm(self::SIGNOFF_KEY, $by, $text);
    }

    /**
     * The dish's recipe in the test household (created on the first run, reused afterwards so repeated runs compare
     * against the same prompt). Nothing outside the operator's household is touched.
     *
     * @param  array{title: string, description: string, side_requirement: string, included_side?: string}  $dish
     */
    private function recipeFor(Household $household, ?User $by, array $dish): Recipe
    {
        $recipe = $household->recipes()->whereNull('archived_at')->where('title', $dish['title'])->orderBy('id')->first();
        if ($recipe !== null) {
            return $recipe;
        }

        $recipe = $this->recipes->quickCreate($household, $by, ['title' => $dish['title'], 'description' => $dish['description']]);

        return $this->recipes->update($recipe, $by, [
            'side_requirement' => $dish['side_requirement'],
            'included_side' => $dish['included_side'] ?? null,
        ]);
    }
}
