<?php

namespace App\Livewire;

use App\Enums\FoodMappingStatus;
use App\Enums\MealAnalysisStatus;
use App\Enums\MealGramsOrigin;
use App\Enums\UsageKind;
use App\Jobs\RunMealAnalysisJob;
use App\Models\AiJob;
use App\Models\MealAnalysis;
use App\Models\MealAnalysisItem;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\MealAnalysisService;
use App\Services\ImageUploadService;
use App\Services\Nutrition\NutritionFormatter;
use App\Services\Nutrition\NutritionResult;
use App\Services\Usage\UsageBalance;
use App\Support\CurrentHousehold;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Skontroluj jedlo" (v2.1 stage 11): upload or take a photo, let the AI name the visible components, correct
 * them, answer at most two clarifying questions, and confirm – with a database calculation or without calories.
 * Everything here is personal to the logged-in person; the household never sees it.
 *
 * @property-read MealAnalysis|null $analysis
 * @property-read AiJob|null $job
 * @property-read string|null $unavailable
 * @property-read UsageBalance|null $balance
 * @property-read bool $noticeAccepted
 * @property-read list<array{item: MealAnalysisItem, candidates: list<array{record_id: int, label: string}>}> $rows
 * @property-read NutritionResult|null $preview
 * @property-read Collection<int, MealAnalysis> $recent
 */
class MealPhotoAnalyzer extends Component
{
    use WithFileUploads;

    #[Url(as: 'analyza')]
    public ?int $analysisId = null;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public string $note = '';

    public string $answer = '';

    public bool $keepPhoto = false;

    /** @var array<int, string> item ID => label being edited */
    public array $labels = [];

    /** @var array<int, string> */
    public array $gramsInput = [];

    /** @var array<int, string> */
    public array $originInput = [];

    /** @var array<int, string> item ID => chosen food record ID ('' = none) */
    public array $choices = [];

    public string $newLabel = '';

    public string $newGrams = '';

    public string $error = '';

    public function mount(): void
    {
        if ($this->analysisId !== null && $this->analysis === null) {
            $this->analysisId = null;
        }
        $this->syncInputs();
    }

    #[Computed]
    public function analysis(): ?MealAnalysis
    {
        if ($this->analysisId === null) {
            return null;
        }
        $analysis = MealAnalysis::query()->with(['items.record'])->find($this->analysisId);
        if ($analysis === null) {
            return null;
        }
        $this->authorize('view', $analysis);

        return $analysis;
    }

    #[Computed]
    public function job(): ?AiJob
    {
        $analysis = $this->analysis;

        return $analysis === null ? null : app(MealAnalysisService::class)->latestJob($analysis);
    }

    #[Computed]
    public function unavailable(): ?string
    {
        return app(AiAvailability::class)->reasonUnavailable(app(CurrentHousehold::class)->get(), UsageKind::MealAnalysis);
    }

    #[Computed]
    public function balance(): ?UsageBalance
    {
        return app(AiAvailability::class)->balance(app(CurrentHousehold::class)->get(), UsageKind::MealAnalysis);
    }

    #[Computed]
    public function noticeAccepted(): bool
    {
        return auth()->user()->hasAcceptedMealPhotoNotice();
    }

    /**
     * @return list<array{item: MealAnalysisItem, candidates: list<array{record_id: int, label: string}>}>
     */
    #[Computed]
    public function rows(): array
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return [];
        }
        $service = app(MealAnalysisService::class);

        $rows = [];
        foreach ($analysis->items as $item) {
            $candidates = collect($service->candidatesFor($item))
                ->map(fn ($c) => ['record_id' => $c->record->id, 'label' => $c->record->displayName().' – '.$c->preparationState->label()])
                ->unique('record_id')
                ->values();
            $record = $item->effectiveRecord();
            if ($record !== null && ! $candidates->contains('record_id', $record->id)) {
                $candidates->prepend(['record_id' => $record->id, 'label' => $record->displayName().' – '.($item->preparation_state ?? $record->preparation_state)->label()]);
            }
            $rows[] = ['item' => $item, 'candidates' => array_values($candidates->all())];
        }

        return $rows;
    }

    /** Live sum of the components under review (never stored; confirm() freezes it). */
    #[Computed]
    public function preview(): ?NutritionResult
    {
        $analysis = $this->analysis;
        if ($analysis === null || $analysis->status !== MealAnalysisStatus::NeedsReview) {
            return null;
        }

        return app(MealAnalysisService::class)->calculate($analysis);
    }

    /** @return Collection<int, MealAnalysis> */
    #[Computed]
    public function recent(): Collection
    {
        return MealAnalysis::query()
            ->where('user_id', auth()->id())
            ->where('status', '!=', MealAnalysisStatus::Discarded)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function acceptNotice(): void
    {
        $user = auth()->user();
        if (! $user->hasAcceptedMealPhotoNotice()) {
            $user->forceFill(['meal_photo_notice_accepted_at' => now()])->save();
        }
        unset($this->noticeAccepted);
    }

    /**
     * Upload the photo and start the recognition. One analysis = one photo = one use; the button is disabled while
     * a use is missing, and a second click on the same photo returns the same analysis.
     */
    public function analyze(MealAnalysisService $service): void
    {
        $this->error = '';
        if (! $this->noticeAccepted) {
            $this->error = __('Najprv potvrď, že fotka sa odošle poskytovateľovi AI.');

            return;
        }

        $this->validate(
            ['photo' => ['required', ...ImageUploadService::rules()], 'note' => ['nullable', 'string', 'max:500']],
            ['photo.required' => __('Vyber alebo odfoť jedlo.'), 'photo.image' => __('Podporované sú iba obrázky JPG, PNG a WebP.'), 'photo.mimes' => __('Podporované sú iba obrázky JPG, PNG a WebP.'), 'photo.max' => __('Fotka je príliš veľká (max. 10 MB).')],
        );

        try {
            $analysis = $service->request(auth()->user(), app(CurrentHousehold::class)->get(), $this->photo, $this->note);
        } catch (AiUnavailableException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->photo = null;
        $this->note = '';
        $this->open($analysis->id);
    }

    /** After a definitive failure: a new attempt with a new use (the previous one was returned). */
    public function retry(MealAnalysisService $service): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        $this->authorize('update', $analysis);
        $this->error = '';

        try {
            $job = $service->analyze($analysis);
            if ($job->wasRecentlyCreated) {
                RunMealAnalysisJob::dispatch($job->id);
            }
        } catch (AiUnavailableException $e) {
            $this->error = $e->getMessage();
        }
        $this->refresh();
    }

    public function refreshStatus(): void
    {
        $this->refresh();
        $this->syncInputs();
    }

    public function open(int $analysisId): void
    {
        $this->analysisId = $analysisId;
        $this->error = '';
        $this->answer = '';
        $this->keepPhoto = false;
        $this->refresh();
        $this->syncInputs();
    }

    public function startNew(): void
    {
        $this->analysisId = null;
        $this->error = '';
        $this->refresh();
    }

    public function saveLabel(int $itemId, MealAnalysisService $service): void
    {
        $item = $this->item($itemId);
        $this->call(fn () => $service->updateItem($item, ['label' => (string) ($this->labels[$itemId] ?? '')]));
    }

    public function saveGrams(int $itemId, MealAnalysisService $service): void
    {
        $item = $this->item($itemId);
        $raw = str_replace(',', '.', trim((string) ($this->gramsInput[$itemId] ?? '')));
        $this->gramsInput[$itemId] = $raw;
        $this->validate(
            ["gramsInput.$itemId" => ['nullable', 'numeric', 'gt:0', 'max:100000'], "originInput.$itemId" => ['required', 'in:confirmed,measured,estimated']],
            [],
            ["gramsInput.$itemId" => __('gramáž'), "originInput.$itemId" => __('pôvod gramáže')],
        );

        $this->call(fn () => $service->updateItem($item, [
            'grams' => $raw === '' ? null : (float) $raw,
            'grams_origin' => MealGramsOrigin::from($this->originInput[$itemId]),
        ]));
    }

    public function updatedChoices(mixed $value, int|string $itemId): void
    {
        $item = $this->item((int) $itemId);
        $this->call(fn () => app(MealAnalysisService::class)->chooseFood($item, (string) $value === '' ? null : (int) $value));
    }

    public function toggleIncluded(int $itemId, MealAnalysisService $service): void
    {
        $item = $this->item($itemId);
        $this->call(fn () => $service->updateItem($item, ['included' => ! $item->included]));
    }

    public function markUnknown(int $itemId, MealAnalysisService $service): void
    {
        $item = $this->item($itemId);
        $this->call(fn () => $service->updateItem($item, ['is_unknown' => ! $item->is_unknown]));
    }

    public function removeItem(int $itemId, MealAnalysisService $service): void
    {
        $item = $this->item($itemId);
        $this->call(fn () => $service->removeItem($item));
    }

    public function addItem(MealAnalysisService $service): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        $this->authorize('update', $analysis);
        $raw = str_replace(',', '.', trim($this->newGrams));
        $this->newGrams = $raw;
        $this->validate(['newLabel' => ['required', 'string', 'max:120'], 'newGrams' => ['nullable', 'numeric', 'gt:0', 'max:100000']], [], ['newLabel' => __('názov zložky'), 'newGrams' => __('gramáž')]);

        $this->call(function () use ($service, $analysis, $raw) {
            $service->addItem($analysis, $this->newLabel, $raw === '' ? null : (float) $raw);
            $this->newLabel = '';
            $this->newGrams = '';
        });
    }

    public function clarify(MealAnalysisService $service): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        $this->authorize('update', $analysis);
        $this->error = '';

        try {
            $service->clarify($analysis, $this->answer);
        } catch (AiUnavailableException|InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->answer = '';
        $this->refresh();
    }

    public function confirm(MealAnalysisService $service, bool $withNutrition = true): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        $this->authorize('update', $analysis);

        $this->call(function () use ($service, $analysis, $withNutrition) {
            $service->confirm($analysis, $withNutrition, $this->keepPhoto);
            Flux::toast(variant: 'success', text: $withNutrition ? __('Jedlo je potvrdené a vypočítané.') : __('Jedlo je uložené bez kalórií.'));
        });
    }

    /** Reopen a confirmed record for corrections (pure arithmetic, no AI, no use). */
    public function edit(): void
    {
        $analysis = $this->analysis;
        if ($analysis === null || $analysis->status !== MealAnalysisStatus::Confirmed) {
            return;
        }
        $this->authorize('update', $analysis);
        $analysis->update(['status' => MealAnalysisStatus::NeedsReview]);
        $this->refresh();
        $this->syncInputs();
    }

    public function discard(MealAnalysisService $service): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        $this->authorize('delete', $analysis);
        $service->discard($analysis);
        $this->startNew();
    }

    public function render(): View
    {
        return view('livewire.meal-photo-analyzer', [
            'formatter' => new NutritionFormatter,
            'maxClarifications' => (int) config('recipes.meal_analysis.max_clarifications', 2),
            'photoTtlHours' => (int) config('recipes.meal_analysis.photo_ttl_hours', 24),
            'draftTtlDays' => (int) config('recipes.meal_analysis.draft_ttl_days', 7),
        ]);
    }

    private function item(int $itemId): MealAnalysisItem
    {
        $analysis = $this->analysis;
        abort_if($analysis === null, 404);
        $this->authorize('update', $analysis);
        $item = $analysis->items->firstWhere('id', $itemId);
        abort_if($item === null, 404);

        return $item;
    }

    /** Run a service change, keep the error message where the person sees it. */
    private function call(callable $change): void
    {
        $this->error = '';
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
        $this->refresh();
        $this->syncInputs();
    }

    private function syncInputs(): void
    {
        $analysis = $this->analysis;
        if ($analysis === null) {
            return;
        }
        foreach ($analysis->items as $item) {
            $this->labels[$item->id] = $item->label;
            $this->gramsInput[$item->id] = $item->grams === null ? '' : rtrim(rtrim(number_format((float) $item->grams, 2, '.', ''), '0'), '.');
            $this->originInput[$item->id] = ($item->grams_origin ?? MealGramsOrigin::Confirmed) === MealGramsOrigin::Estimated ? MealGramsOrigin::Confirmed->value : ($item->grams_origin ?? MealGramsOrigin::Confirmed)->value;
            $record = $item->effectiveRecord();
            $this->choices[$item->id] = $record === null || $item->mapping_status === FoodMappingStatus::Rejected ? '' : (string) $record->id;
        }
    }

    private function refresh(): void
    {
        unset($this->analysis, $this->job, $this->rows, $this->preview, $this->recent, $this->balance, $this->unavailable);
    }
}
