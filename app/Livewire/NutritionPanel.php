<?php

namespace App\Livewire;

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodMappingStatus;
use App\Models\FoodSourceRecord;
use App\Models\IngredientFoodMapping;
use App\Models\IngredientLine;
use App\Models\NutritionCalculation;
use App\Models\Recipe;
use App\Services\Food\FoodCandidate;
use App\Services\Food\FoodMappingService;
use App\Services\Food\IngredientMatcher;
use App\Services\Nutrition\NutritionFormatter;
use App\Services\Nutrition\RecipeNutrition;
use App\Support\CurrentHousehold;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Vypočítať výživové hodnoty" on the recipe detail (v2.1 stage 10). Step 1 proposes foods for every ingredient
 * line, step 2 lets a person confirm ambiguous foods, grams, state and the share that is eaten, step 3 shows the
 * stored result per recipe, per serving and – only with a final weight – per 100 g. Reading needs recipe view
 * rights; changing mappings or recalculating needs edit rights. Pure arithmetic: no AI, no usage, no Plus gate.
 *
 * @property-read Recipe $recipe
 * @property-read NutritionCalculation|null $calculation
 * @property-read bool $canEdit
 * @property-read list<array<string, mixed>> $review
 */
class NutritionPanel extends Component
{
    /** Percent of a line that is eaten; anything else is typed as grams instead. */
    public const SHARE_OPTIONS = [100, 75, 50, 25, 0];

    public int $recipeId;

    public bool $reviewing = false;

    public string $finalWeight = '';

    /** @var array<int, int> line ID => percent eaten */
    public array $shares = [];

    /** @var array<int, string> line ID => chosen food record ID ('' = none) */
    public array $choices = [];

    /** @var array<int, string> */
    public array $gramsInput = [];

    /** @var array<int, string> */
    public array $originInput = [];

    /** @var array<int, bool> */
    public array $editingGrams = [];

    public string $error = '';

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;

        $calculation = $this->calculation;
        $this->finalWeight = $calculation?->final_weight_g === null ? '' : (string) (float) $calculation->final_weight_g;
        foreach ($calculation?->components ?? [] as $component) {
            if (isset($component['line_id'])) {
                $this->shares[(int) $component['line_id']] = (int) round((float) ($component['share'] ?? 1.0) * 100);
            }
        }
    }

    #[Computed]
    public function recipe(): Recipe
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())
            ->with('ingredients.foodMapping.record.conversions')
            ->findOrFail($this->recipeId);
        $this->authorize('view', $recipe);

        return $recipe;
    }

    #[Computed]
    public function calculation(): ?NutritionCalculation
    {
        return app(RecipeNutrition::class)->current($this->recipe);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->can('update', $this->recipe);
    }

    /**
     * Rows of the review step: the line, its mapping, the dictionary candidates to choose from and whether the line
     * still needs an amount before the result is meaningful.
     *
     * @return list<array{line: IngredientLine, mapping: IngredientFoodMapping|null, record: FoodSourceRecord|null, candidates: list<array{record_id: int, label: string}>, needs_amount: bool}>
     */
    #[Computed]
    public function review(): array
    {
        $matcher = app(IngredientMatcher::class);
        $needsAmount = app(RecipeNutrition::class)->linesNeedingAmount($this->recipe, $this->shareFractions())->pluck('id')->all();

        $rows = [];
        foreach ($this->recipe->ingredients as $line) {
            $mapping = $line->foodMapping;
            $record = $mapping?->status === FoodMappingStatus::Rejected ? null : $mapping?->record;

            $candidates = collect($matcher->propose($line)->candidates)
                ->map(fn (FoodCandidate $candidate) => ['record_id' => $candidate->record->id, 'label' => $candidate->record->displayName().' – '.$candidate->preparationState->label()])
                ->unique('record_id')
                ->values();
            if ($record !== null && ! $candidates->contains('record_id', $record->id)) {
                $candidates->prepend(['record_id' => $record->id, 'label' => $record->displayName().' – '.($mapping->preparation_state ?? $record->preparation_state)->label()]);
            }

            $rows[] = [
                'line' => $line,
                'mapping' => $mapping,
                'record' => $record,
                'candidates' => $candidates->all(),
                'needs_amount' => in_array($line->id, $needsAmount, true),
            ];
        }

        return $rows;
    }

    /**
     * Open the review with fresh proposals. Confirmed and rejected decisions from earlier runs are kept.
     */
    public function start(RecipeNutrition $nutrition): void
    {
        $this->authorize('update', $this->recipe);

        $nutrition->prepare($this->recipe);
        $this->refresh();

        $this->error = '';
        $this->editingGrams = [];
        $this->gramsInput = [];
        $this->resetValidation();

        foreach ($this->recipe->ingredients as $line) {
            $this->shares[$line->id] ??= 100;
            $this->originInput[$line->id] = FoodGramsOrigin::UserEntered->value;
            $record = $line->foodMapping?->status === FoodMappingStatus::Rejected ? null : $line->foodMapping?->record;
            $this->choices[$line->id] = $record === null ? '' : (string) $record->id;
        }

        $this->reviewing = true;
    }

    /**
     * A food was picked from the candidates ('' = this line has no food and stays out of the sum).
     */
    public function updatedChoices(mixed $value, int|string $lineId): void
    {
        $this->authorize('update', $this->recipe);
        $line = $this->line((int) $lineId);
        $mappings = app(FoodMappingService::class);
        $this->error = '';

        if ((string) $value === '') {
            $mappings->reject($line, auth()->user());
            $this->refresh();

            return;
        }

        $candidate = collect(app(IngredientMatcher::class)->propose($line)->candidates)->first(fn (FoodCandidate $c) => $c->record->id === (int) $value);
        if ($candidate === null) {
            $this->error = __('Zvolená potravina nie je medzi návrhmi pre „:name“.', ['name' => $line->name]);
            $this->refresh();

            return;
        }

        $mappings->confirm($line, $candidate->record, $candidate->preparationState, null, null, auth()->user());
        unset($this->editingGrams[$line->id]);
        $this->refresh();
    }

    /**
     * Grams a person typed for one line, marked as entered or as an estimate.
     */
    public function saveGrams(int $lineId, FoodMappingService $mappings): void
    {
        $this->authorize('update', $this->recipe);
        $line = $this->line($lineId);
        $mapping = $line->foodMapping;
        $record = $mapping?->status === FoodMappingStatus::Rejected ? null : $mapping?->record;
        if ($record === null) {
            $this->error = __('Najprv zvoľ potravinu pre „:name“.', ['name' => $line->name]);

            return;
        }

        $this->gramsInput[$lineId] = str_replace(',', '.', trim((string) ($this->gramsInput[$lineId] ?? '')));
        $this->validate(
            ["gramsInput.$lineId" => ['required', 'numeric', 'gt:0', 'max:100000'], "originInput.$lineId" => ['required', 'in:user_entered,estimated']],
            [],
            ["gramsInput.$lineId" => __('gramáž'), "originInput.$lineId" => __('pôvod gramáže')],
        );

        try {
            $mappings->confirm(
                $line,
                $record,
                $mapping->preparation_state ?? $record->preparation_state,
                (float) $this->gramsInput[$lineId],
                FoodGramsOrigin::from($this->originInput[$lineId]),
                auth()->user(),
            );
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->error = '';
        unset($this->editingGrams[$lineId], $this->gramsInput[$lineId]);
        $this->refresh();
    }

    /**
     * Drop typed grams and go back to what the recipe amount converts to (if it converts).
     */
    public function useRecipeAmount(int $lineId, FoodMappingService $mappings): void
    {
        $this->authorize('update', $this->recipe);
        $line = $this->line($lineId);
        $mapping = $line->foodMapping;
        $record = $mapping?->status === FoodMappingStatus::Rejected ? null : $mapping?->record;
        if ($record === null) {
            return;
        }

        $mappings->confirm($line, $record, $mapping->preparation_state ?? $record->preparation_state, null, null, auth()->user());
        unset($this->editingGrams[$lineId], $this->gramsInput[$lineId]);
        $this->refresh();
    }

    /**
     * Step 3: calculate with the current mappings and shares. Lines with real energy and no grams block the run
     * with a clear message – the person types grams or chooses "nezapočítať".
     */
    public function compute(RecipeNutrition $nutrition): void
    {
        $this->authorize('update', $this->recipe);
        $this->error = '';

        $this->finalWeight = str_replace(',', '.', trim($this->finalWeight));
        $this->validate(['finalWeight' => ['nullable', 'numeric', 'gt:0', 'max:100000']], [], ['finalWeight' => __('konečná hmotnosť')]);

        $nutrition->prepare($this->recipe);
        $this->refresh();

        $shares = $this->shareFractions();
        $needing = $nutrition->linesNeedingAmount($this->recipe, $shares);
        if ($needing->isNotEmpty()) {
            if (! $this->reviewing) {
                $this->start($nutrition);
            }
            $this->error = __('Zadaj gramáž alebo zvoľ „nezapočítať“ pri: :names.', ['names' => $needing->pluck('name')->implode(', ')]);

            return;
        }

        $nutrition->calculate($this->recipe, auth()->user(), $this->finalWeight === '' ? null : (float) $this->finalWeight, $shares);

        $this->reviewing = false;
        $this->editingGrams = [];
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Výživové hodnoty sú vypočítané.'));
    }

    public function cancel(): void
    {
        $this->reviewing = false;
        $this->error = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.nutrition-panel', [
            'formatter' => new NutritionFormatter,
            'nutrients' => FoodSourceRecord::NUTRIENTS,
            'shareOptions' => self::SHARE_OPTIONS,
        ]);
    }

    /**
     * @return array<int, float>
     */
    private function shareFractions(): array
    {
        return array_map(fn (mixed $percent) => max(0, min(100, (int) $percent)) / 100, $this->shares);
    }

    private function line(int $lineId): IngredientLine
    {
        $line = $this->recipe->ingredients->firstWhere('id', $lineId);
        abort_if($line === null, 404);

        return $line;
    }

    private function refresh(): void
    {
        unset($this->recipe, $this->calculation, $this->review);
    }
}
