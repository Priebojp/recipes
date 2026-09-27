<?php

namespace App\Livewire;

use App\Enums\ConsumptionSource;
use App\Enums\ManualNutritionOrigin;
use App\Enums\MealAnalysisStatus;
use App\Enums\PortionMode;
use App\Models\FoodSourceRecord;
use App\Models\MealAnalysis;
use App\Models\MealConsumption;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Services\Diary\ConsumptionBasis;
use App\Services\Diary\ConsumptionCalculator;
use App\Services\Diary\ConsumptionPortion;
use App\Services\Diary\ConsumptionResult;
use App\Services\Diary\MealDiaryService;
use App\Services\Nutrition\NutritionFormatter;
use App\Services\Nutrition\RecipeNutrition;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The private diary "Zjedol som" (v2.1 stage 12): one local day at a time, its entries, a day sum that says when it
 * is partial, the detail of every snapshot, a correction of the eaten portion and deletion. New entries come from
 * a recipe with a calculation, a confirmed photo or a manual meal. Pure arithmetic – no AI, no usage, no Plus –
 * and nothing here touches the cooking history.
 *
 * @property-read CarbonImmutable $day
 * @property-read Collection<int, MealConsumption> $entries
 * @property-read array{totals: array<string, float|null>|null, partial: bool, without_values: int, entries: int} $dayTotals
 * @property-read Collection<int, Recipe> $recipes
 * @property-read Collection<int, MealAnalysis> $analyses
 * @property-read ConsumptionBasis|null $basis
 * @property-read ConsumptionResult|null $preview
 * @property-read NutritionCalculation|null $calculation
 */
class MealDiary extends Component
{
    public const FRACTION_OPTIONS = [25, 50, 75, 100, 150, 200];

    public const SHARE_OPTIONS = [100, 75, 50, 25, 0];

    #[Url(as: 'den')]
    public ?string $dayParam = null;

    #[Url(as: 'recept')]
    public ?int $recipeId = null;

    #[Url(as: 'analyza')]
    public ?int $analysisId = null;

    public bool $formOpen = false;

    public string $source = ConsumptionSource::Recipe->value;

    public string $title = '';

    public string $eatenDate = '';

    public string $eatenTime = '';

    public string $portionMode = PortionMode::Fraction->value;

    public string $fraction = '100';

    public string $gramsEaten = '';

    /** @var array<int, int> component index => percent eaten */
    public array $componentShares = [];

    /** @var array<string, string> nutrient => typed value */
    public array $manual = [];

    public string $manualOrigin = '';

    public string $note = '';

    public bool $withNutrition = true;

    public ?int $adjustingId = null;

    public ?int $expandedId = null;

    public string $error = '';

    /** Set for one render after a save so the page emits the property-less analytics event once. */
    public bool $logged = false;

    public function mount(): void
    {
        [$recipeId, $analysisId] = [$this->recipeId, $this->analysisId];
        $this->resetForm();

        // Opened from a recipe, the cooked panel or a confirmed photo: the form starts on that source.
        if ($recipeId !== null) {
            $this->recipeId = $recipeId;
            $this->source = ConsumptionSource::Recipe->value;
            $this->formOpen = true;
        } elseif ($analysisId !== null) {
            $this->analysisId = $analysisId;
            $this->source = ConsumptionSource::Analysis->value;
            $this->formOpen = true;
        }
        // Resolving them authorizes them: a foreign analysis is a 403, a foreign recipe a 404.
        $this->selectedRecipe();
        $this->selectedAnalysis();
    }

    #[Computed]
    public function day(): CarbonImmutable
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        if ($this->dayParam === null) {
            return $today;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->dayParam, $this->timezone())->startOfDay();
        } catch (\Throwable) {
            return $today;
        }
    }

    /** @return Collection<int, MealConsumption> */
    #[Computed]
    public function entries(): Collection
    {
        return app(MealDiaryService::class)->entriesOn(auth()->user(), $this->day);
    }

    /** @return array{totals: array<string, float|null>|null, partial: bool, without_values: int, entries: int} */
    #[Computed]
    public function dayTotals(): array
    {
        return app(MealDiaryService::class)->dayTotals($this->entries);
    }

    /** Recipes of the household that have a calculation to log from. */
    /** @return Collection<int, Recipe> */
    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->whereHas('nutritionCalculations')
            ->orderBy('title')
            ->get(['id', 'title', 'household_id']);
    }

    /** The person's own confirmed photos, newest first. */
    /** @return Collection<int, MealAnalysis> */
    #[Computed]
    public function analyses(): Collection
    {
        return MealAnalysis::query()
            ->where('user_id', auth()->id())
            ->where('status', MealAnalysisStatus::Confirmed)
            ->orderByDesc('confirmed_at')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function calculation(): ?NutritionCalculation
    {
        $recipe = $this->selectedRecipe();

        return $recipe === null ? null : app(RecipeNutrition::class)->current($recipe);
    }

    /** What the form would compute from: the frozen source, or the previous snapshot when correcting. */
    #[Computed]
    public function basis(): ?ConsumptionBasis
    {
        $diary = app(MealDiaryService::class);

        if ($this->adjustingId !== null) {
            $snapshot = $this->entry($this->adjustingId)->snapshot;

            return $snapshot === null || ! $snapshot->hasNutrition() ? null : ConsumptionBasis::fromSnapshot($snapshot);
        }

        return match ($this->source) {
            ConsumptionSource::Recipe->value => $this->calculation === null ? null : $diary->basisForCalculation($this->calculation),
            ConsumptionSource::Analysis->value => $this->selectedAnalysis() === null ? null : $diary->basisForAnalysis($this->selectedAnalysis()),
            default => null,
        };
    }

    /** Live "this is what will be saved"; null when nothing can be computed yet. */
    #[Computed]
    public function preview(): ?ConsumptionResult
    {
        $basis = $this->basis;
        if ($basis === null || ($this->source === ConsumptionSource::Manual->value && $this->adjustingId === null)) {
            return null;
        }
        try {
            return app(ConsumptionCalculator::class)->calculate($basis, $this->portion());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function showDay(string $day): void
    {
        $this->dayParam = $day;
        $this->expandedId = null;
        $this->refresh();
    }

    public function previousDay(): void
    {
        $this->showDay($this->day->subDay()->toDateString());
    }

    public function nextDay(): void
    {
        $this->showDay($this->day->addDay()->toDateString());
    }

    public function today(): void
    {
        $this->dayParam = null;
        $this->expandedId = null;
        $this->refresh();
    }

    public function openForm(?string $source = null): void
    {
        $this->resetForm();
        if ($source !== null && ConsumptionSource::tryFrom($source) !== null) {
            $this->source = $source;
        }
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->formOpen = false;
        $this->adjustingId = null;
    }

    public function updatedSource(): void
    {
        $this->recipeId = null;
        $this->analysisId = null;
        $this->componentShares = [];
        $this->error = '';
        $this->refresh();
    }

    public function updatedRecipeId(): void
    {
        $this->componentShares = [];
        $this->refresh();
    }

    public function updatedAnalysisId(): void
    {
        $this->componentShares = [];
        $this->refresh();
    }

    public function updatedPortionMode(): void
    {
        $this->error = '';
        $this->refresh();
    }

    public function save(MealDiaryService $diary): void
    {
        $this->error = '';
        $this->validate($this->rules(), [], $this->attributeNames());

        $eatenAt = $this->eatenAt();
        $household = app(CurrentHousehold::class)->get();
        $user = auth()->user();
        $note = $this->note;

        try {
            $entry = match ($this->source) {
                ConsumptionSource::Recipe->value => $diary->logRecipe($user, $household, $this->requireRecipe(), $this->portion(), $eatenAt, $this->timezone(), $note, $this->withNutrition),
                ConsumptionSource::Analysis->value => $diary->logAnalysis($user, $this->requireAnalysis(), $this->portion(), $eatenAt, $this->timezone(), $note),
                default => $diary->logManual($user, $household, $this->title, $this->withNutrition ? $this->manualValues() : null, ManualNutritionOrigin::tryFrom($this->manualOrigin), $eatenAt, $this->timezone(), $note),
            };
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dayParam = $entry->eaten_on->toDateString() === CarbonImmutable::now($this->timezone())->toDateString() ? null : $entry->eaten_on->toDateString();
        $this->expandedId = $entry->id;
        $this->logged = true;
        $this->closeForm();
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Zapísané do denníka.'));
    }

    /** Open the correction of how much was eaten; the numbers come from the entry's own frozen snapshot. */
    public function startAdjust(int $entryId): void
    {
        $entry = $this->entry($entryId);
        $this->authorize('update', $entry);
        $snapshot = $entry->snapshot;
        if ($snapshot === null || ! $snapshot->hasNutrition()) {
            $this->error = __('Záznam bez kalórií nemá čo prepočítať.');

            return;
        }

        $this->resetForm();
        $this->formOpen = false;
        $this->adjustingId = $entry->id;
        $this->expandedId = $entry->id;
        $this->portionMode = $snapshot->portion_mode->value;
        $this->fraction = $snapshot->portion_fraction === null ? '100' : (string) (int) round((float) $snapshot->portion_fraction * 100);
        $this->gramsEaten = $snapshot->grams === null ? '' : (string) (float) $snapshot->grams;
        foreach ($snapshot->component_shares ?? [] as $index => $share) {
            $this->componentShares[(int) $index] = (int) round((float) $share * 100);
        }
        $this->refresh();
    }

    public function saveAdjust(MealDiaryService $diary): void
    {
        if ($this->adjustingId === null) {
            return;
        }
        $entry = $this->entry($this->adjustingId);
        $this->authorize('update', $entry);
        $this->error = '';
        $this->validate($this->portionRules(), [], $this->attributeNames());

        try {
            $diary->adjust($entry, $this->portion());
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->adjustingId = null;
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Podiel je opravený; pôvodná snímka zostáva v histórii.'));
    }

    public function cancelAdjust(): void
    {
        $this->adjustingId = null;
        $this->error = '';
        $this->resetValidation();
    }

    public function remove(int $entryId, MealDiaryService $diary): void
    {
        $entry = $this->entry($entryId);
        $this->authorize('delete', $entry);
        $diary->delete($entry);
        if ($this->expandedId === $entryId) {
            $this->expandedId = null;
        }
        if ($this->adjustingId === $entryId) {
            $this->adjustingId = null;
        }
        $this->refresh();
        Flux::toast(text: __('Záznam je zmazaný.'));
    }

    public function toggle(int $entryId): void
    {
        $this->expandedId = $this->expandedId === $entryId ? null : $entryId;
    }

    public function render(): View
    {
        $logged = $this->logged;
        $this->logged = false;

        return view('livewire.meal-diary', [
            'formatter' => new NutritionFormatter,
            'nutrients' => FoodSourceRecord::NUTRIENTS,
            'fractionOptions' => self::FRACTION_OPTIONS,
            'shareOptions' => self::SHARE_OPTIONS,
            'sources' => ConsumptionSource::cases(),
            'origins' => ManualNutritionOrigin::cases(),
            'timezone' => $this->timezone(),
            'today' => CarbonImmutable::now($this->timezone())->toDateString(),
            'justLogged' => $logged,
        ]);
    }

    public function selectedRecipe(): ?Recipe
    {
        if ($this->recipeId === null) {
            return null;
        }
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->findOrFail($this->recipeId);
        $this->authorize('view', $recipe);

        return $recipe;
    }

    public function selectedAnalysis(): ?MealAnalysis
    {
        if ($this->analysisId === null) {
            return null;
        }
        $analysis = MealAnalysis::query()->findOrFail($this->analysisId);
        $this->authorize('view', $analysis);

        return $analysis;
    }

    private function requireRecipe(): Recipe
    {
        return $this->selectedRecipe() ?? throw new InvalidArgumentException(__('Vyber recept.'));
    }

    private function requireAnalysis(): MealAnalysis
    {
        return $this->selectedAnalysis() ?? throw new InvalidArgumentException(__('Vyber potvrdené jedlo z fotky.'));
    }

    private function entry(int $entryId): MealConsumption
    {
        return MealConsumption::query()->where('user_id', auth()->id())->with('snapshots')->findOrFail($entryId);
    }

    private function portion(): ConsumptionPortion
    {
        return match (PortionMode::tryFrom($this->portionMode) ?? PortionMode::Fraction) {
            PortionMode::Grams => ConsumptionPortion::grams((float) str_replace(',', '.', $this->gramsEaten)),
            PortionMode::PerComponent => ConsumptionPortion::perComponent(array_map(fn (mixed $percent) => max(0, min(100, (int) $percent)) / 100, $this->componentShares)),
            PortionMode::Fraction => ConsumptionPortion::fraction(max(0, (int) $this->fraction) / 100),
        };
    }

    /** @return array<string, float|null> */
    private function manualValues(): array
    {
        $values = [];
        foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
            $raw = str_replace(',', '.', trim((string) ($this->manual[$nutrient] ?? '')));
            $values[$nutrient] = $raw === '' ? null : (float) $raw;
        }

        return $values;
    }

    private function eatenAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->eatenDate.' '.$this->eatenTime, $this->timezone());
    }

    private function timezone(): string
    {
        return app(CurrentHousehold::class)->timezone();
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        $manual = $this->source === ConsumptionSource::Manual->value;
        $rules = array_merge([
            'source' => ['required', 'in:recipe,analysis,manual'],
            'eatenDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($this->timezone())->toDateString()],
            'eatenTime' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
            'title' => [$manual ? 'required' : 'nullable', 'string', 'max:200'],
            'manualOrigin' => ['nullable', 'in:label,estimate'],
        ], $manual ? [] : $this->portionRules());
        foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
            $this->manual[$nutrient] = str_replace(',', '.', trim((string) ($this->manual[$nutrient] ?? '')));
            $rules["manual.$nutrient"] = ['nullable', 'numeric', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    /** @return array<string, list<mixed>> */
    private function portionRules(): array
    {
        $this->gramsEaten = str_replace(',', '.', trim($this->gramsEaten));

        return [
            'portionMode' => ['required', 'in:fraction,grams,per_component'],
            'fraction' => ['required_if:portionMode,fraction', 'integer', 'min:1', 'max:1000'],
            'gramsEaten' => ['required_if:portionMode,grams', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'componentShares.*' => ['integer', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        $names = ['eatenDate' => __('dátum'), 'eatenTime' => __('čas'), 'title' => __('názov jedla'), 'fraction' => __('podiel'), 'gramsEaten' => __('gramy'), 'note' => __('poznámka'), 'manualOrigin' => __('zdroj hodnôt')];
        foreach (FoodSourceRecord::NUTRIENTS as $nutrient) {
            $names["manual.$nutrient"] = NutritionFormatter::label($nutrient);
        }

        return $names;
    }

    private function resetForm(): void
    {
        $now = CarbonImmutable::now($this->timezone());
        $this->recipeId = null;
        $this->analysisId = null;
        $this->title = '';
        $this->eatenDate = ($this->dayParam ?? $now->toDateString());
        $this->eatenTime = $this->dayParam === null || $this->dayParam === $now->toDateString() ? $now->format('H:i') : '12:00';
        $this->portionMode = PortionMode::Fraction->value;
        $this->fraction = '100';
        $this->gramsEaten = '';
        $this->componentShares = [];
        $this->manual = array_fill_keys(FoodSourceRecord::NUTRIENTS, '');
        $this->manualOrigin = '';
        $this->note = '';
        $this->withNutrition = true;
        $this->error = '';
        $this->resetValidation();
    }

    private function refresh(): void
    {
        unset($this->day, $this->entries, $this->dayTotals, $this->recipes, $this->analyses, $this->calculation, $this->basis, $this->preview);
    }
}
