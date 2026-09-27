<?php

namespace App\Services\Ai;

use App\Ai\Agents\MealAnalysisAgent;
use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\FoodMappingStatus;
use App\Enums\FoodPreparationState;
use App\Enums\MealAnalysisAiStatus;
use App\Enums\MealAnalysisStatus;
use App\Enums\MealGramsOrigin;
use App\Enums\UsageKind;
use App\Jobs\RunMealAnalysisJob;
use App\Models\AiJob;
use App\Models\FoodSourceRecord;
use App\Models\Household;
use App\Models\MealAnalysis;
use App\Models\MealAnalysisItem;
use App\Models\User;
use App\Services\Food\FoodCandidate;
use App\Services\Food\IngredientMatcher;
use App\Services\ImageUploadService;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Nutrition\NutritionComponent;
use App\Services\Nutrition\NutritionResult;
use App\Services\Usage\InsufficientUsageException;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Photo → AI proposal of visible components → the person corrects and confirms → database calculation
 * (v2.1 stage 11). The model only names candidates, states and estimates; every number shown comes from the food
 * database of stage 9 through the calculator of stage 10. One analysis = one photo and one delivered proposal;
 * a photo without food costs the person nothing, clarifications in the same session are not charged again.
 */
class MealAnalysisService
{
    public function __construct(
        private AiAvailability $availability,
        private AiSettings $settings,
        private AiCostCalculator $costs,
        private AiJobLifecycle $lifecycle,
        private ImageUploadService $uploads,
        private IngredientMatcher $matcher,
        private NutritionCalculator $calculator,
    ) {}

    /**
     * Store the photo (re-encoded without EXIF, shrunk) and open the analysis. Nothing is sent anywhere yet.
     */
    public function upload(User $by, Household $household, UploadedFile|string $photo, ?string $note = null): MealAnalysis
    {
        return DB::transaction(function () use ($by, $household, $photo, $note) {
            $analysis = MealAnalysis::create([
                'household_id' => $household->id,
                'user_id' => $by->id,
                'status' => MealAnalysisStatus::Uploaded,
                'note' => $this->clean($note, 500) ?: null,
                'expires_at' => now()->addDays((int) config('recipes.meal_analysis.draft_ttl_days', 7)),
            ]);
            $this->uploads->addMealPhoto($analysis, $photo);

            return $analysis;
        });
    }

    /**
     * Upload, reserve one meal-analysis use and queue the recognition.
     *
     * @throws AiUnavailableException
     */
    public function request(User $by, Household $household, UploadedFile|string $photo, ?string $note = null): MealAnalysis
    {
        $analysis = $this->upload($by, $household, $photo, $note);
        $job = $this->analyze($analysis);
        if ($job->wasRecentlyCreated) {
            RunMealAnalysisJob::dispatch($job->id);
        }

        return $analysis->fresh();
    }

    /**
     * Create (or reuse) the charged root job of the analysis. A running, held or delivered job is returned as is –
     * a double click or a retry never reserves a second use; only after a definitive failure a new attempt starts.
     * With $rateLimits=false the daily and concurrency caps are skipped (operator measurement).
     *
     * @throws AiUnavailableException
     */
    public function analyze(MealAnalysis $analysis, bool $rateLimits = true): AiJob
    {
        $root = $analysis->aiJob;
        if ($root !== null && $root->status !== AiJobStatus::Failed) {
            return $root;
        }
        if (! $analysis->hasPhoto()) {
            throw new AiUnavailableException(__('Fotka už nie je k dispozícii – nahraj ju znova.'));
        }

        $version = (string) config('recipes.ai.meal_analysis_prompt_version');
        $attempt = AiJob::query()->where('input->meal_analysis_id', $analysis->id)->whereNull('parent_ai_job_id')->count();
        $key = hash('sha256', implode('|', ['meal', $analysis->id, $version, $attempt]));

        $existing = AiJob::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $household = $analysis->household;
        if ($reason = $this->availability->reasonUnavailable($household, UsageKind::MealAnalysis, $rateLimits)) {
            throw new AiUnavailableException($reason);
        }

        try {
            return DB::transaction(function () use ($analysis, $household, $version, $key) {
                $job = $this->lifecycle->create([
                    'household_id' => $household->id,
                    'recipe_id' => null,
                    'kind' => AiJobKind::MealAnalysis,
                    'status' => AiJobStatus::Queued,
                    'request_key' => $key,
                    'provider' => $this->availability->textProvider(),
                    'model' => $this->settings->textModel(),
                    'profile' => $this->settings->textProfile(),
                    'prompt_version' => $version,
                    'input' => ['meal_analysis_id' => $analysis->id, 'note' => $analysis->note],
                    'created_by' => $analysis->user_id,
                ], UsageKind::MealAnalysis);

                $analysis->update(['ai_job_id' => $job->id, 'status' => MealAnalysisStatus::Analyzing]);

                return $job;
            });
        } catch (InsufficientUsageException $e) {
            throw new AiUnavailableException($e->getMessage(), previous: $e);
        }
    }

    /**
     * A follow-up inside the same session: the person answers the model's questions and the proposal is redone.
     * Not charged (no reservation), limited in number, measured on its own job under the root job.
     *
     * @throws AiUnavailableException|InvalidArgumentException
     */
    public function clarify(MealAnalysis $analysis, string $answer): AiJob
    {
        $answer = $this->clean($answer, 500);
        if ($answer === '') {
            throw new InvalidArgumentException(__('Napíš odpoveď na otázku.'));
        }
        if ($analysis->status !== MealAnalysisStatus::NeedsReview || $analysis->aiJob === null) {
            throw new InvalidArgumentException(__('Doplniť sa dá iba doručený návrh.'));
        }
        $max = (int) config('recipes.meal_analysis.max_clarifications', 2);
        if ($analysis->clarification_count >= $max) {
            throw new InvalidArgumentException(__('Viac doplnení v tejto analýze nie je možných (max. :max). Zložky uprav ručne alebo nahraj novú fotku.', ['max' => $max]));
        }
        if ($this->latestJob($analysis)?->status->isActive()) {
            throw new InvalidArgumentException(__('Predchádzajúce doplnenie ešte beží.'));
        }
        if (! $analysis->hasPhoto()) {
            throw new AiUnavailableException(__('Fotka už nie je k dispozícii – doplnenie potrebuje pôvodnú fotku.'));
        }
        if ($reason = $this->availability->reasonUnavailable($analysis->household, UsageKind::MealAnalysis, ledger: false)) {
            throw new AiUnavailableException($reason);
        }

        $version = (string) config('recipes.ai.meal_analysis_prompt_version');
        $round = $analysis->clarification_count + 1;

        $job = DB::transaction(function () use ($analysis, $answer, $version, $round) {
            $job = $this->lifecycle->create([
                'household_id' => $analysis->household_id,
                'recipe_id' => null,
                'parent_ai_job_id' => $analysis->ai_job_id,
                'kind' => AiJobKind::MealAnalysis,
                'status' => AiJobStatus::Queued,
                'request_key' => hash('sha256', implode('|', ['meal-clarify', $analysis->id, $version, $round])),
                'provider' => $this->availability->textProvider(),
                'model' => $this->settings->textModel(),
                'profile' => $this->settings->textProfile(),
                'prompt_version' => $version,
                'input' => [
                    'meal_analysis_id' => $analysis->id,
                    'note' => $analysis->note,
                    'clarification' => [
                        'round' => $round,
                        'questions' => $analysis->questions ?? [],
                        'answer' => $answer,
                        'previous' => ['dish_name' => $analysis->dish_name, 'components' => $analysis->items->pluck('label')->values()->all()],
                    ],
                ],
                'created_by' => $analysis->user_id,
            ], null);

            $analysis->update(['clarification_count' => $round, 'status' => MealAnalysisStatus::Analyzing]);

            return $job;
        });

        RunMealAnalysisJob::dispatch($job->id);

        return $job->fresh();
    }

    /**
     * Executed by the queued job: sends the photo and the note (nothing else), validates the answer and settles
     * the use – consumed for a delivered proposal, returned for a photo without recognisable food.
     */
    public function run(AiJob $job): void
    {
        if (! $this->lifecycle->start($job)) {
            return;
        }
        $startedAt = now();
        $analysis = MealAnalysis::query()->find((int) ($job->input['meal_analysis_id'] ?? 0));

        try {
            if ($analysis === null) {
                throw new InvalidArgumentException('Analýza jedla už neexistuje.');
            }
            $media = $analysis->hasPhoto() ? $analysis->photo() : null;
            if ($media === null || ! is_file($media->getPath())) {
                throw new InvalidArgumentException('Fotka analýzy už nie je k dispozícii.');
            }

            $prompt = (string) json_encode(array_filter([
                'note' => $job->input['note'] ?? null,
                'clarification' => $job->input['clarification'] ?? null,
            ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $job->update(['prompt' => $prompt]);

            $response = MealAnalysisAgent::make()->withReasoningEffort($job->profile['reasoning_effort'] ?? 'default')->prompt(
                $prompt,
                [Image::fromPath($media->getPath(), $media->mime_type)],
                provider: $job->provider,
                model: $job->model,
                timeout: $this->settings->timeoutSeconds(),
            );

            $structured = $response instanceof StructuredAgentResponse
                ? $response->toArray()
                : (json_decode($response->text, true) ?: []);
            $output = $this->validateOutput(is_array($structured) ? $structured : []);

            $attributes = ['output' => $output, 'duration_ms' => $this->elapsedMs($startedAt), ...$this->costs->attributesFor($job, $response->usage)];
            $status = MealAnalysisAiStatus::from($output['status']);

            DB::transaction(function () use ($job, $analysis, $output, $status, $attributes) {
                if ($status->isDelivered()) {
                    $this->lifecycle->succeed($job, $attributes);
                    $this->applyProposal($analysis, $output, $status);
                } else {
                    // The provider answered, but there is nothing to check: the use goes back, the cost stays on the job.
                    $this->lifecycle->succeedWithoutCharge($job, $attributes);
                    $this->applyUnusable($analysis, $output, $status);
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->lifecycle->fail($job, $e, ['duration_ms' => $this->elapsedMs($startedAt)]);
            if ($analysis !== null) {
                $this->afterFailure($analysis, $job->fresh());
            }
        }
    }

    /**
     * Server-side validation of the model's answer. Only the fields of the schema survive, trimmed and capped;
     * identifiers, calories or probabilities the model may have added are dropped here (acceptance scenario 6).
     *
     * @param  array<string, mixed>  $output
     * @return array{status: string, dish_name: string|null, components: list<array<string, mixed>>, questions: list<string>, limitations: list<string>}
     */
    public function validateOutput(array $output): array
    {
        $string = fn ($v, int $max) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
        $strings = fn ($v, int $count, int $max) => array_values(array_filter(array_map(fn ($s) => $string($s, $max), array_slice((array) $v, 0, $count))));

        $status = MealAnalysisAiStatus::tryFrom((string) ($output['status'] ?? '')) ?? MealAnalysisAiStatus::Unusable;

        $components = [];
        if ($status->isDelivered()) {
            foreach (array_slice((array) ($output['components'] ?? []), 0, (int) config('recipes.meal_analysis.max_items', 30)) as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $label = $string($component['label'] ?? '', 120);
                if ($label === '') {
                    continue;
                }
                $grams = $component['estimated_grams'] ?? null;
                $grams = is_numeric($grams) && (float) $grams > 0 && (float) $grams <= 5000 ? round((float) $grams, 1) : null;

                $components[] = [
                    'label' => $label,
                    'is_unknown' => (bool) ($component['is_unknown'] ?? false),
                    'alternatives' => $strings($component['alternatives'] ?? [], 5, 120),
                    'preparation_state' => (FoodPreparationState::tryFrom((string) ($component['preparation_state'] ?? '')) ?? FoodPreparationState::Unknown)->value,
                    'estimated_grams' => $grams,
                    'portion_basis' => $string($component['portion_basis'] ?? '', 200) ?: null,
                    'visible_evidence' => $string($component['visible_evidence'] ?? '', 300) ?: null,
                    'assumptions' => $strings($component['assumptions'] ?? [], 5, 300),
                ];
            }
        }

        return [
            'status' => $status->value,
            'dish_name' => $string($output['dish_name'] ?? '', 200) ?: null,
            'components' => $components,
            'questions' => $strings($output['questions'] ?? [], 2, 300),
            'limitations' => $strings($output['limitations'] ?? [], 5, 300),
        ];
    }

    /**
     * Dictionary candidates for an item, best first – what the person may choose from. Nothing outside the
     * curated dictionary is offered, so a food ID never comes from the model or the browser unverified.
     *
     * @return list<FoodCandidate>
     */
    public function candidatesFor(MealAnalysisItem $item): array
    {
        $candidates = [];
        foreach ([$item->label, ...($item->alternatives ?? [])] as $name) {
            foreach ($this->matcher->proposeName($name, $item->grams === null ? null : (float) $item->grams)->candidates as $candidate) {
                if (! isset($candidates[$candidate->record->id])) {
                    $candidates[$candidate->record->id] = $candidate;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * The person's decision about an item: rename, set grams with their origin, or mark it unknown.
     *
     * @param  array{label?: string, grams?: float|null, grams_origin?: MealGramsOrigin|null, is_unknown?: bool, included?: bool}  $data
     */
    public function updateItem(MealAnalysisItem $item, array $data): MealAnalysisItem
    {
        $this->assertEditable($item->analysis);
        $changes = [];

        if (array_key_exists('label', $data)) {
            $label = $this->clean($data['label'], 120);
            if ($label === '') {
                throw new InvalidArgumentException(__('Názov zložky nesmie byť prázdny.'));
            }
            if ($label !== $item->label) {
                $changes['label'] = $label;
                // A renamed component is looked up again; the previous food no longer applies.
                $best = $this->bestCandidate($label, $item->grams === null ? null : (float) $item->grams);
                $changes['food_source_record_id'] = $best?->record->id;
                $changes['preparation_state'] = $best !== null ? $best->preparationState : $item->preparation_state;
                $changes['mapping_status'] = $best === null ? FoodMappingStatus::Unresolved : FoodMappingStatus::Suggested;
            }
        }
        if (array_key_exists('grams', $data)) {
            $grams = $data['grams'];
            if ($grams !== null && ($grams <= 0 || $grams > 100000)) {
                throw new InvalidArgumentException(__('Gramáž musí byť medzi 0 a 100 000 g.'));
            }
            $changes['grams'] = $grams === null ? null : round($grams, 2);
            $changes['grams_origin'] = $grams === null ? null : ($data['grams_origin'] ?? MealGramsOrigin::Confirmed);
        }
        if (array_key_exists('is_unknown', $data)) {
            $changes['is_unknown'] = (bool) $data['is_unknown'];
            if ($changes['is_unknown']) {
                $changes['food_source_record_id'] = null;
                $changes['mapping_status'] = FoodMappingStatus::Unresolved;
            }
        }
        if (array_key_exists('included', $data)) {
            $changes['included'] = (bool) $data['included'];
        }

        $item->update($changes);

        return $item->fresh();
    }

    /**
     * A component the person adds themselves (something the photo did not show, or the model missed).
     */
    public function addItem(MealAnalysis $analysis, string $label, ?float $grams = null, MealGramsOrigin $origin = MealGramsOrigin::Confirmed): MealAnalysisItem
    {
        $this->assertEditable($analysis);
        $label = $this->clean($label, 120);
        if ($label === '') {
            throw new InvalidArgumentException(__('Názov zložky nesmie byť prázdny.'));
        }
        if ($analysis->items()->count() >= (int) config('recipes.meal_analysis.max_items', 30)) {
            throw new InvalidArgumentException(__('Jedlo má už maximálny počet zložiek.'));
        }
        if ($grams !== null && ($grams <= 0 || $grams > 100000)) {
            throw new InvalidArgumentException(__('Gramáž musí byť medzi 0 a 100 000 g.'));
        }

        $best = $this->bestCandidate($label, $grams);

        return $analysis->items()->create([
            'position' => (int) $analysis->items()->max('position') + 1,
            'label' => $label,
            'alternatives' => [],
            'preparation_state' => $best !== null ? $best->preparationState : FoodPreparationState::Unknown,
            'estimated_grams' => null,
            'grams' => $grams === null ? null : round($grams, 2),
            'grams_origin' => $grams === null ? null : $origin,
            'assumptions' => [],
            'food_source_record_id' => $best?->record->id,
            'mapping_status' => $best === null ? FoodMappingStatus::Unresolved : FoodMappingStatus::Suggested,
            'is_unknown' => false,
            'included' => true,
        ]);
    }

    public function removeItem(MealAnalysisItem $item): void
    {
        $this->assertEditable($item->analysis);
        $item->delete();
    }

    /**
     * The person picks a food for an item from the server's candidates (null = no food: the item stays in the
     * list but out of the sum). Any other ID is refused – the browser cannot smuggle one in.
     */
    public function chooseFood(MealAnalysisItem $item, ?int $recordId): MealAnalysisItem
    {
        $this->assertEditable($item->analysis);

        if ($recordId === null) {
            $item->update(['food_source_record_id' => null, 'mapping_status' => FoodMappingStatus::Rejected]);

            return $item->fresh();
        }

        $candidate = collect($this->candidatesFor($item))->first(fn (FoodCandidate $c) => $c->record->id === $recordId);
        if ($candidate === null) {
            throw new InvalidArgumentException(__('Zvolená potravina nie je medzi návrhmi pre „:name“.', ['name' => $item->label]));
        }

        $item->update([
            'food_source_record_id' => $candidate->record->id,
            'preparation_state' => $candidate->preparationState,
            'mapping_status' => FoodMappingStatus::Confirmed,
            'is_unknown' => false,
        ]);

        return $item->fresh();
    }

    /**
     * Pure calculation over the current items – the same arithmetic as a recipe (stage 10), one serving, no
     * final weight. Nothing is stored; confirm() freezes the result.
     */
    public function calculate(MealAnalysis $analysis): NutritionResult
    {
        $analysis->loadMissing('items.record');
        $components = array_values($analysis->items->where('included', true)->map(fn (MealAnalysisItem $item) => $this->component($item))->all());

        return $this->calculator->calculate($components, 1, null);
    }

    /**
     * The person checked the components: freeze the calculation (or none, when saving without calories), close the
     * draft and decide the photo's fate. Estimates stay marked as estimates in the stored assumptions.
     */
    public function confirm(MealAnalysis $analysis, bool $withNutrition = true, bool $keepPhoto = false): MealAnalysis
    {
        if (! in_array($analysis->status, [MealAnalysisStatus::NeedsReview, MealAnalysisStatus::Confirmed], true)) {
            throw new InvalidArgumentException(__('Potvrdiť sa dá iba doručený návrh.'));
        }
        if ($this->latestJob($analysis)?->status->isActive()) {
            throw new InvalidArgumentException(__('Počkaj na dokončenie doplnenia.'));
        }

        return DB::transaction(function () use ($analysis, $withNutrition, $keepPhoto) {
            foreach ($analysis->items as $item) {
                if ($item->mapping_status === FoodMappingStatus::Suggested && $item->food_source_record_id !== null) {
                    $item->update(['mapping_status' => FoodMappingStatus::Confirmed]);
                }
            }

            $nutrition = null;
            if ($withNutrition) {
                $result = $this->calculate($analysis->fresh(['items.record']));
                $nutrition = [
                    'calculation_version' => NutritionCalculator::VERSION,
                    'totals' => $result->totals,
                    'completeness' => $result->completeness->value,
                    'components' => $result->components,
                    'missing' => $result->missing,
                    'assumptions' => $result->assumptions,
                    'included_grams' => $result->includedGrams,
                    'calculated_at' => now()->toIso8601String(),
                ];
            }

            $analysis->update([
                'status' => MealAnalysisStatus::Confirmed,
                'nutrition' => $nutrition,
                'confirmed_at' => now(),
                'expires_at' => null,
                'photo_retain_until' => $keepPhoto || $analysis->photo_removed_at !== null ? null : ($analysis->photo_retain_until ?? $this->photoRetainUntil()),
            ]);

            return $analysis->fresh(['items.record']);
        });
    }

    /**
     * Throw the analysis away: the photo goes immediately, the record stays as discarded until the draft cleanup.
     */
    public function discard(MealAnalysis $analysis): void
    {
        DB::transaction(function () use ($analysis) {
            $this->removePhoto($analysis);
            $analysis->update(['status' => MealAnalysisStatus::Discarded, 'expires_at' => now(), 'nutrition' => null]);
            $analysis->items()->delete();
        });
    }

    /** Delete the working photo file; the analysis and its components stay. */
    public function removePhoto(MealAnalysis $analysis): void
    {
        $analysis->clearMediaCollection(MealAnalysis::PHOTO_COLLECTION);
        $analysis->update(['photo_removed_at' => $analysis->photo_removed_at ?? now(), 'photo_retain_until' => null]);
    }

    /** The most recent job of the analysis: the root or its latest clarification. */
    public function latestJob(MealAnalysis $analysis): ?AiJob
    {
        if ($analysis->ai_job_id === null) {
            return null;
        }

        return AiJob::query()
            ->where(fn ($q) => $q->whereKey($analysis->ai_job_id)->orWhere('parent_ai_job_id', $analysis->ai_job_id))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array{status: string, dish_name: string|null, components: list<array<string, mixed>>, questions: list<string>, limitations: list<string>}  $output
     */
    private function applyProposal(MealAnalysis $analysis, array $output, MealAnalysisAiStatus $status): void
    {
        $analysis->items()->delete();

        foreach ($output['components'] as $position => $component) {
            $grams = $component['estimated_grams'];
            $best = $component['is_unknown'] ? null : $this->bestCandidate($component['label'], $grams, $component['alternatives']);

            $analysis->items()->create([
                'position' => $position,
                'label' => $component['label'],
                'alternatives' => $component['alternatives'],
                'preparation_state' => $best !== null ? $best->preparationState : FoodPreparationState::from($component['preparation_state']),
                'estimated_grams' => $grams,
                'grams' => $grams,
                'grams_origin' => $grams === null ? null : MealGramsOrigin::Estimated,
                'portion_basis' => $component['portion_basis'],
                'visible_evidence' => $component['visible_evidence'],
                'assumptions' => $component['assumptions'],
                'food_source_record_id' => $best?->record->id,
                'mapping_status' => $best === null ? FoodMappingStatus::Unresolved : FoodMappingStatus::Suggested,
                'is_unknown' => $component['is_unknown'],
                'included' => true,
            ]);
        }

        $analysis->update([
            'status' => MealAnalysisStatus::NeedsReview,
            'ai_result' => $output,
            'ai_status' => $status,
            'dish_name' => $output['dish_name'],
            'questions' => $output['questions'],
            'limitations' => $output['limitations'],
            'photo_retain_until' => $analysis->photo_retain_until ?? $this->photoRetainUntil(),
        ]);
    }

    /**
     * @param  array{status: string, dish_name: string|null, components: list<array<string, mixed>>, questions: list<string>, limitations: list<string>}  $output
     */
    private function applyUnusable(MealAnalysis $analysis, array $output, MealAnalysisAiStatus $status): void
    {
        $analysis->items()->delete();
        $analysis->update([
            'status' => MealAnalysisStatus::Unusable,
            'ai_result' => $output,
            'ai_status' => $status,
            'dish_name' => null,
            'questions' => [],
            'limitations' => $output['limitations'],
            'nutrition' => null,
            'photo_retain_until' => $analysis->photo_retain_until ?? $this->photoRetainUntil(),
        ]);
    }

    /**
     * A definitive failure returns the analysis to "uploaded" so the person may try again (new use); an ambiguous
     * outcome keeps it analysing until the operator resolves the job. A failed clarification keeps the proposal.
     */
    private function afterFailure(MealAnalysis $analysis, AiJob $job): void
    {
        if ($job->status !== AiJobStatus::Failed) {
            return;
        }
        $analysis->update([
            'status' => $job->parent_ai_job_id === null ? MealAnalysisStatus::Uploaded : MealAnalysisStatus::NeedsReview,
            'photo_retain_until' => $analysis->photo_retain_until ?? $this->photoRetainUntil(),
        ]);
    }

    /**
     * @param  list<string>  $alternatives
     */
    private function bestCandidate(string $label, ?float $grams, array $alternatives = []): ?FoodCandidate
    {
        foreach ([$label, ...$alternatives] as $name) {
            $best = $this->matcher->proposeName($name, $grams)->best();
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    private function component(MealAnalysisItem $item): NutritionComponent
    {
        $record = $item->effectiveRecord();
        $grams = $item->grams === null ? null : (float) $item->grams;

        return new NutritionComponent(
            name: $item->label,
            amount: $grams === null ? '' : rtrim(rtrim(number_format($grams, 1, '.', ''), '0'), '.').' g',
            grams: $record === null ? null : $grams,
            gramsOrigin: $record === null || $item->grams_origin === null ? null : $item->grams_origin->foodGramsOrigin(),
            nutrients: $record?->nutrients(),
            source: $record === null ? null : [
                'record_id' => $record->id,
                'provider' => $record->provider,
                'external_id' => $record->external_id,
                'name' => $record->name,
                'name_sk' => $record->name_sk,
                'license' => $record->license,
                'basis' => $record->basis,
                'preparation_state' => ($item->preparation_state ?? $record->preparation_state)->value,
            ],
            preparationState: $record === null ? null : ($item->preparation_state ?? $record->preparation_state),
            share: 1.0,
            unresolvedReason: $this->unresolvedReason($item, $record, $grams),
            lineId: $item->id,
        );
    }

    private function unresolvedReason(MealAnalysisItem $item, ?FoodSourceRecord $record, ?float $grams): ?string
    {
        $reason = match (true) {
            $item->is_unknown => __('Neznáma zložka – bez potraviny a bez hodnôt.'),
            $item->mapping_status === FoodMappingStatus::Rejected => __('Bez zvolenej potraviny.'),
            $record === null => __('V slovníku potravín nie je zhoda.'),
            $grams === null => __('Chýba gramáž.'),
            default => null,
        };

        return is_string($reason) ? $reason : null;
    }

    private function assertEditable(MealAnalysis $analysis): void
    {
        if (! in_array($analysis->status, [MealAnalysisStatus::NeedsReview, MealAnalysisStatus::Confirmed], true)) {
            throw new InvalidArgumentException(__('Zložky sa dajú upravovať až po doručení návrhu.'));
        }
    }

    private function photoRetainUntil(): CarbonInterface
    {
        return now()->addHours((int) config('recipes.meal_analysis.photo_ttl_hours', 24));
    }

    private function clean(?string $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function elapsedMs(CarbonInterface $since): int
    {
        return max(0, (int) $since->diffInMilliseconds(now()));
    }
}
