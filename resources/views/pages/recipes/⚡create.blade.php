<?php

use App\Enums\MealType;
use App\Services\RecipeService;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nový recept')] class extends Component {
    public string $title = '';

    public string $description = '';

    /** @var list<string> */
    public array $mealTypes = [];

    #[Computed]
    public function duplicate(): bool
    {
        return trim($this->title) !== '' && app(RecipeService::class)->duplicateTitleExists(app(CurrentHousehold::class)->get(), $this->title);
    }

    public function save(RecipeService $recipes, bool $thenEdit = false): void
    {
        $this->title = trim($this->title);
        $this->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'mealTypes' => ['array'],
            'mealTypes.*' => ['in:breakfast,lunch,dinner'],
        ], ['title.required' => 'Zadaj názov jedla.', 'title.max' => 'Názov môže mať najviac 200 znakov.']);

        $recipe = $recipes->quickCreate(app(CurrentHousehold::class)->get(), auth()->user(), [
            'title' => $this->title,
            'description' => $this->description,
            'meal_types' => $this->mealTypes,
        ]);

        \Flux\Flux::toast(variant: 'success', text: 'Recept je uložený. Dá sa hneď vyhľadať aj použiť v generátore.');

        $this->redirectRoute($thenEdit ? 'recipes.edit' : 'recipes.show', $recipe, navigate: true);
    }
}; ?>

<div class="mx-auto max-w-xl space-y-6">
    <x-page-header title="Nový recept" :back="route('recipes.index')" />

    <form wire:submit="save" class="space-y-5">
        <flux:input wire:model.live.debounce.500ms="title" label="Názov" placeholder="napr. Praženica" required autofocus maxlength="200" description="Jediná povinná položka." data-test="recipe-title" />

        @if ($this->duplicate)
            <flux:callout icon="information-circle" variant="secondary">Recept s rovnakým názvom už existuje. Uložiť sa dá aj tak, ale zváž krátky opis na rozlíšenie.</flux:callout>
        @endif

        <flux:textarea wire:model="description" label="Krátky opis" rows="2" placeholder="napr. Kuracie kúsky na paprike so smotanovou omáčkou" description="Odporúčané pri nejednoznačnom názve, nie povinné." />

        <flux:checkbox.group wire:model="mealTypes" label="Typ jedla" description="Prázdne = nezaradené; také recepty sa v generátore predvolene ponúkajú tiež.">
            @foreach (MealType::cases() as $type)
                <flux:checkbox :value="$type->value" :label="$type->label()" />
            @endforeach
        </flux:checkbox.group>

        <div class="flex flex-col gap-2 sm:flex-row">
            <flux:button type="submit" variant="primary" class="w-full" data-test="recipe-save">Uložiť</flux:button>
            <flux:button wire:click="save(true)" class="w-full">Uložiť a doplniť podrobnosti</flux:button>
        </div>
    </form>
</div>
