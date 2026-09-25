<?php

use App\Enums\MealType;
use App\Enums\ServingMode;
use App\Enums\SideRequirement;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Services\ImageUploadService;
use App\Services\RecipeService;
use App\Services\StaleRecipeException;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public int $recipeId;

    public int $version;

    public string $title = '';

    public string $description = '';

    /** @var list<string> */
    public array $mealTypes = [];

    public ?int $baseServings = null;

    public ?int $prepMinutes = null;

    public ?int $cookMinutes = null;

    public string $sideRequirement = 'unknown';

    public string $includedSide = '';

    public string $servingMode = 'auto';

    public string $rawText = '';

    public string $source = '';

    public string $notes = '';

    /** @var list<array{id: int|null, name: string, amount: string, unit: string, note: string}> */
    public array $ingredients = [];

    /** @var list<array{id: int|null, text: string}> */
    public array $steps = [];

    public $cover = null;

    /** @var array<int, mixed> */
    public array $stepImages = [];

    public string $error = '';

    public function mount(Recipe $recipe): void
    {
        abort_unless($recipe->household_id === app(CurrentHousehold::class)->id(), 404);
        $this->authorize('update', $recipe);
        $this->recipeId = $recipe->id;
        $this->fillFrom($recipe);
    }

    private function fillFrom(Recipe $recipe): void
    {
        $recipe->load(['ingredients', 'steps', 'mealTypes']);
        $this->version = $recipe->version;
        $this->title = $recipe->title;
        $this->description = (string) $recipe->description;
        $this->mealTypes = array_map(fn ($t) => $t->value, $recipe->mealTypeEnums());
        $this->baseServings = $recipe->base_servings;
        $this->prepMinutes = $recipe->prep_minutes;
        $this->cookMinutes = $recipe->cook_minutes;
        $this->sideRequirement = $recipe->side_requirement->value;
        $this->includedSide = (string) $recipe->included_side;
        $this->servingMode = $recipe->serving_mode->value;
        $this->rawText = (string) $recipe->raw_text;
        $this->source = (string) $recipe->source;
        $this->notes = (string) $recipe->notes;
        $this->ingredients = $recipe->ingredients->map(fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'amount' => $l->numeric_amount !== null ? rtrim(rtrim($l->numeric_amount, '0'), '.') : (string) $l->text_amount,
            'unit' => (string) $l->unit,
            'note' => (string) $l->note,
        ])->values()->all();
        $this->steps = $recipe->steps->map(fn ($s) => ['id' => $s->id, 'text' => $s->text])->values()->all();
    }

    #[Computed]
    public function recipe(): Recipe
    {
        return Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->with(['cover', 'media', 'steps.media'])->findOrFail($this->recipeId);
    }

    public function addIngredient(): void
    {
        $this->ingredients[] = ['id' => null, 'name' => '', 'amount' => '', 'unit' => '', 'note' => ''];
    }

    public function removeIngredient(int $index): void
    {
        unset($this->ingredients[$index]);
        $this->ingredients = array_values($this->ingredients);
    }

    public function moveIngredient(int $index, int $direction): void
    {
        $this->swap($this->ingredients, $index, $index + $direction);
    }

    public function addStep(): void
    {
        $this->steps[] = ['id' => null, 'text' => ''];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        $this->swap($this->steps, $index, $index + $direction);
    }

    private function swap(array &$list, int $a, int $b): void
    {
        if (! isset($list[$a], $list[$b])) {
            return;
        }
        [$list[$a], $list[$b]] = [$list[$b], $list[$a]];
        $list = array_values($list);
    }

    public function save(RecipeService $recipes, bool $stay = false): void
    {
        $this->title = trim($this->title);
        $this->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mealTypes.*' => ['in:breakfast,lunch,dinner'],
            'baseServings' => ['nullable', 'integer', 'min:1', 'max:500'],
            'prepMinutes' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'cookMinutes' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'sideRequirement' => ['in:unknown,complete,needs_side'],
            'servingMode' => ['in:auto,plate,bowl,pot,casserole,baking_dish'],
            'includedSide' => ['nullable', 'string', 'max:200'],
            'rawText' => ['nullable', 'string', 'max:20000'],
            'source' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'ingredients.*.name' => ['nullable', 'string', 'max:200'],
            'ingredients.*.amount' => ['nullable', 'string', 'max:100'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'ingredients.*.note' => ['nullable', 'string', 'max:200'],
            'steps.*.text' => ['nullable', 'string', 'max:4000'],
        ], ['title.required' => 'Zadaj názov jedla.']);

        try {
            $recipe = $recipes->update($this->recipe, auth()->user(), [
                'title' => $this->title,
                'description' => $this->description,
                'meal_types' => $this->mealTypes,
                'base_servings' => $this->baseServings,
                'prep_minutes' => $this->prepMinutes,
                'cook_minutes' => $this->cookMinutes,
                'side_requirement' => SideRequirement::from($this->sideRequirement),
                'included_side' => $this->includedSide,
                'serving_mode' => ServingMode::from($this->servingMode),
                'raw_text' => $this->rawText,
                'source' => $this->source,
                'notes' => $this->notes,
                'ingredients' => $this->ingredients,
                'steps' => $this->steps,
            ], $this->version);
        } catch (StaleRecipeException $e) {
            $this->error = $e->getMessage().' Obnov stránku a skús to znova; tvoje zmeny zostanú vo formulári, kým neodídeš.';

            return;
        }

        $this->error = '';
        $this->fillFrom($recipe);
        unset($this->recipe);
        $this->dispatch('recipe-saved');
        \Flux\Flux::toast(variant: 'success', text: 'Recept uložený.');

        if (! $stay) {
            $this->redirectRoute('recipes.show', $recipe, navigate: true);
        }
    }

    public function updatedCover(ImageUploadService $uploads): void
    {
        $this->validate(['cover' => ImageUploadService::rules()], ['cover.image' => 'Podporované sú iba obrázky JPG, PNG a WebP.', 'cover.mimes' => 'Podporované sú iba obrázky JPG, PNG a WebP.', 'cover.max' => 'Obrázok je príliš veľký.']);
        $uploads->addCover($this->recipe, $this->cover);
        $this->cover = null;
        unset($this->recipe);
        \Flux\Flux::toast(variant: 'success', text: 'Fotografia nahraná. Predchádzajúca sa dá obnoviť.');
    }

    public function updatedStepImages($value, string $key, ImageUploadService $uploads): void
    {
        $index = (int) $key;
        $step = $this->steps[$index] ?? null;
        if ($step === null || $step['id'] === null) {
            $this->addError('stepImages.'.$index, 'Najprv ulož recept, potom pridaj fotografiu kroku.');

            return;
        }

        $this->validate(['stepImages.'.$index => ImageUploadService::rules()], ['*' => 'Podporované sú iba obrázky JPG, PNG a WebP do 10 MB.']);
        $model = RecipeStep::query()->where('recipe_id', $this->recipeId)->findOrFail($step['id']);
        $uploads->addStepImage($model, $this->stepImages[$index]);
        unset($this->stepImages[$index]);
        unset($this->recipe);
    }

    public function removeStepImage(int $mediaId): void
    {
        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($mediaId);
        $step = RecipeStep::query()->where('recipe_id', $this->recipeId)->findOrFail($media->model_id);
        abort_unless($media->model_type === $step->getMorphClass(), 404);
        $media->delete();
        unset($this->recipe);
    }

    public function activateCover(int $mediaId, ImageUploadService $uploads): void
    {
        $media = $this->recipe->getMedia(Recipe::COVER_COLLECTION)->firstWhere('id', $mediaId);
        if ($media) {
            $uploads->activateCover($this->recipe, $media);
            unset($this->recipe);
        }
    }

    public function removeCover(ImageUploadService $uploads): void
    {
        $uploads->removeCover($this->recipe);
        unset($this->recipe);
    }

    public function deleteCoverMedia(int $mediaId): void
    {
        $media = $this->recipe->getMedia(Recipe::COVER_COLLECTION)->firstWhere('id', $mediaId);
        if ($media && $this->recipe->cover_media_id !== $media->id) {
            $media->delete();
            unset($this->recipe);
        }
    }

    #[On('ai-applied')]
    public function reloadAfterAi(): void
    {
        unset($this->recipe);
        $this->fillFrom($this->recipe);
    }

    public function destroy(RecipeService $recipes): void
    {
        $this->authorize('delete', $this->recipe);
        $recipes->destroy($this->recipe);
        $this->redirectRoute('recipes.index', navigate: true);
    }

    public function title(): string
    {
        return 'Upraviť: '.$this->title;
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-8" x-data="{ dirty: false }" x-init="window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } })" @input="dirty = true" @recipe-saved.window="dirty = false">
    @php($recipe = $this->recipe)
    <x-page-header title="Upraviť recept" :back="route('recipes.show', $recipe)" />

    @if ($error)
        <flux:callout icon="exclamation-triangle" variant="danger">{{ $error }}</flux:callout>
    @endif

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
            <flux:input wire:model="title" label="Názov" required maxlength="200" />
            <flux:textarea wire:model="description" label="Krátky opis" rows="2" />
            <flux:checkbox.group wire:model="mealTypes" label="Typ jedla">
                @foreach (MealType::cases() as $type)
                    <flux:checkbox :value="$type->value" :label="$type->label()" />
                @endforeach
            </flux:checkbox.group>
            <div class="grid grid-cols-3 gap-3">
                <flux:input type="number" min="1" wire:model="baseServings" label="Základné porcie" />
                <flux:input type="number" min="0" wire:model="prepMinutes" label="Príprava (min)" />
                <flux:input type="number" min="0" wire:model="cookMinutes" label="Varenie (min)" />
            </div>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg">Hlavná fotografia</flux:heading>
            <div class="flex flex-col gap-3 sm:flex-row">
                <x-recipe-cover :recipe="$recipe" conversion="thumb" class="aspect-[4/3] w-full rounded-xl sm:w-56" />
                <div class="flex-1 space-y-2">
                    <flux:input type="file" wire:model="cover" label="Nahrať vlastnú fotografiu" accept="image/jpeg,image/png,image/webp" />
                    <div wire:loading wire:target="cover" class="text-sm text-zinc-500">Nahrávam…</div>
                    @error('cover')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
                    @if ($recipe->cover)
                        <flux:button size="xs" variant="ghost" wire:click="removeCover">Bez fotografie</flux:button>
                    @endif
                    <livewire:ai-image-assistant :recipe-id="$recipe->id" :key="'ai-image-'.$recipe->id" />
                </div>
            </div>
            @php($covers = $recipe->getMedia(Recipe::COVER_COLLECTION))
            @if ($covers->count() > 1 || ($covers->count() === 1 && ! $recipe->cover))
                <div class="space-y-1">
                    <flux:text class="text-sm font-medium">Predchádzajúce fotografie</flux:text>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($covers as $media)
                            @continue($recipe->cover_media_id === $media->id)
                            <div class="relative">
                                <img src="{{ route('media.show', [$media, 'thumb']) }}" class="h-20 rounded-lg object-cover" alt="" />
                                @if ($media->getCustomProperty('origin') === 'ai')<span class="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] text-white">AI</span>@endif
                                <div class="mt-1 flex gap-1">
                                    <flux:button size="xs" wire:click="activateCover({{ $media->id }})">Obnoviť</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteCoverMedia({{ $media->id }})" aria-label="Vymazať" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Suroviny <span class="text-sm font-normal text-zinc-500">({{ count(array_filter($ingredients, fn ($l) => trim($l['name']) !== '')) }})</span></flux:heading>
                <flux:button size="sm" icon="plus" wire:click="addIngredient" data-test="add-ingredient">Pridať riadok</flux:button>
            </div>
            <flux:text class="text-xs text-zinc-500">Množstvo môže byť číslo (2, 1/2, 1,5) alebo text („podľa chuti“, „trochu“) alebo prázdne.</flux:text>
            @foreach ($ingredients as $i => $line)
                <div class="grid grid-cols-12 gap-2" wire:key="ing-{{ $i }}-{{ $line['id'] ?? 'new' }}">
                    <div class="col-span-3"><flux:input wire:model="ingredients.{{ $i }}.amount" placeholder="Množstvo" size="sm" /></div>
                    <div class="col-span-2"><flux:input wire:model="ingredients.{{ $i }}.unit" placeholder="Jedn." size="sm" /></div>
                    <div class="col-span-5"><flux:input wire:model="ingredients.{{ $i }}.name" placeholder="Surovina" size="sm" /></div>
                    <div class="col-span-2 flex items-center gap-0.5">
                        <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveIngredient({{ $i }}, -1)" aria-label="Hore" />
                        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveIngredient({{ $i }}, 1)" aria-label="Dole" />
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeIngredient({{ $i }})" aria-label="Odstrániť" />
                    </div>
                    <div class="col-span-12"><flux:input wire:model="ingredients.{{ $i }}.note" placeholder="Poznámka (voliteľné)" size="sm" /></div>
                </div>
            @endforeach
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">Postup</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="addStep" data-test="add-step">Pridať krok</flux:button>
            </div>
            @foreach ($steps as $i => $step)
                <div class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="step-{{ $i }}-{{ $step['id'] ?? 'new' }}">
                    <div class="flex gap-2">
                        <span class="mt-2 text-sm font-semibold text-zinc-500">{{ $i + 1 }}.</span>
                        <flux:textarea wire:model="steps.{{ $i }}.text" rows="3" class="flex-1" placeholder="Text kroku" />
                        <div class="flex flex-col gap-0.5">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveStep({{ $i }}, -1)" aria-label="Hore" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveStep({{ $i }}, 1)" aria-label="Dole" />
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeStep({{ $i }})" aria-label="Odstrániť" />
                        </div>
                    </div>
                    @if ($step['id'])
                        @php($stepModel = $recipe->steps->firstWhere('id', $step['id']))
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @foreach ($stepModel?->getMedia(RecipeStep::IMAGES_COLLECTION) ?? [] as $image)
                                <div class="relative">
                                    <img src="{{ route('media.show', [$image, 'thumb']) }}" class="h-16 rounded object-cover" alt="" />
                                    <button type="button" wire:click="removeStepImage({{ $image->id }})" class="absolute -right-1 -top-1 rounded-full bg-white p-0.5 shadow dark:bg-zinc-800" aria-label="Odstrániť fotografiu"><flux:icon name="x-mark" class="size-3" /></button>
                                </div>
                            @endforeach
                            <label class="cursor-pointer text-xs text-zinc-500">
                                <span class="inline-flex items-center gap-1 rounded border border-dashed border-zinc-300 px-2 py-1 dark:border-zinc-600"><flux:icon name="photo" class="size-4" /> Pridať fotku</span>
                                <input type="file" wire:model="stepImages.{{ $i }}" accept="image/jpeg,image/png,image/webp" class="hidden" />
                            </label>
                            <span wire:loading wire:target="stepImages.{{ $i }}" class="text-xs text-zinc-500">Nahrávam…</span>
                            @error('stepImages.'.$i)<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                        </div>
                    @else
                        <div class="mt-1 text-xs text-zinc-500">Fotografie kroku sa dajú pridať po uložení.</div>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="space-y-4">
            <flux:heading size="lg">Ďalšie údaje</flux:heading>
            <flux:select wire:model.live="sideRequirement" label="Potreba prílohy">
                @foreach (SideRequirement::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="includedSide" label="Príloha v recepte (explicitne)" placeholder="napr. ryža" description="Zmienka v opise sa nepovažuje za potvrdenie prílohy." />
            <flux:select wire:model="servingMode" label="Vzhľad pre AI fotografiu">
                @foreach (ServingMode::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="rawText" label="Pôvodný voľný text" rows="6" description="Sem môžeš vložiť celý zápis receptu tak, ako ho máš. AI ho vie rozpísať na suroviny a kroky ako návrh na schválenie." />
            <flux:input wire:model="source" label="Zdroj" placeholder="odkaz alebo poznámka" />
            <flux:textarea wire:model="notes" label="Poznámky" rows="2" />
        </section>

        <div class="sticky bottom-20 z-10 flex gap-2 rounded-xl border border-zinc-200 bg-white/95 p-2 backdrop-blur lg:bottom-4 dark:border-zinc-700 dark:bg-zinc-800/95">
            <flux:button type="submit" variant="primary" class="flex-1" data-test="recipe-save">Uložiť</flux:button>
            <flux:button wire:click="save(true)" class="flex-1">Uložiť a pokračovať v úprave</flux:button>
        </div>
    </form>

    <section class="space-y-3">
        <flux:heading size="lg">Úprava textu pomocou AI</flux:heading>
        <livewire:ai-text-assistant :recipe-id="$recipe->id" :key="'ai-text-'.$recipe->id" />
    </section>

    <section class="rounded-xl border border-red-200 p-4 dark:border-red-900">
        <flux:heading size="sm">Nebezpečná zóna</flux:heading>
        <flux:text class="mb-2 text-sm">Archivácia je bezpečná (recept zostane v pláne a histórii). Úplné vymazanie ponechá v histórii iba uložený názov.</flux:text>
        <flux:button variant="danger" size="sm" icon="trash" wire:click="destroy" wire:confirm="Naozaj úplne vymazať recept? História si ponechá iba názov.">Vymazať recept</flux:button>
    </section>
</div>
