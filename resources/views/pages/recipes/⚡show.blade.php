<?php

use App\Enums\Preference;
use App\Models\CookingEvent;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\SelectionSession;
use App\Services\PreferenceService;
use App\Services\RecipeService;
use App\Services\ServingScaler;
use App\Support\CurrentHousehold;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public int $recipeId;

    #[Url(as: 'session')]
    public ?string $sessionId = null;

    public ?int $targetServings = null;

    public function mount(Recipe $recipe): void
    {
        abort_unless($recipe->household_id === app(CurrentHousehold::class)->id(), 404);
        $this->authorize('view', $recipe);
        $this->recipeId = $recipe->id;
        $this->targetServings = $recipe->base_servings;
    }

    #[Computed]
    public function recipe(): Recipe
    {
        return Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())
            ->with(['cover', 'mealTypes', 'ingredients', 'steps.media', 'preferences.person', 'exclusions'])
            ->findOrFail($this->recipeId);
    }

    #[Computed]
    public function session(): ?SelectionSession
    {
        return $this->sessionId ? SelectionSession::query()->where('household_id', app(CurrentHousehold::class)->id())->find($this->sessionId) : null;
    }

    #[Computed]
    public function activePerson(): ?Person
    {
        return app(CurrentHousehold::class)->activePerson(auth()->user());
    }

    #[Computed]
    public function scaledIngredients(): array
    {
        return (new ServingScaler)->scale($this->recipe->ingredients, $this->recipe->base_servings, $this->targetServings);
    }

    #[Computed]
    public function history()
    {
        return CookingEvent::query()->where('recipe_id', $this->recipeId)->active()->with('people')->orderByDesc('cooked_on')->limit(5)->get();
    }

    public function title(): string
    {
        return $this->recipe->title;
    }

    public function toggleFavorite(PreferenceService $preferences): void
    {
        $this->authorize('update', $this->recipe);
        if ($person = $this->activePerson) {
            $preferences->toggleFavorite($person, $this->recipe);
            unset($this->recipe);
        }
    }

    #[On('preferences-changed')]
    public function refreshRecipe(): void
    {
        unset($this->recipe);
    }

    #[On('cooked-saved')]
    public function refreshHistory(): void
    {
        unset($this->history);
    }

    public function wantToCook(): void
    {
        $defaults = ['person_ids' => app(CurrentHousehold::class)->get()->default_person_ids ?? []];
        if ($session = $this->session) {
            $defaults = [
                'person_ids' => $session->inputs['person_ids'],
                'meal_type' => $session->inputs['meal_type'] ?? 'any',
                'term' => $session->inputs['term']['term'] ?? 'today',
                'date' => $session->inputs['term']['scheduled_date'] ?? null,
            ];
        }
        if ($this->targetServings) {
            $defaults['servings'] = $this->targetServings;
        }

        $this->dispatch('open-plan-panel', recipeId: $this->recipeId, defaults: $defaults, sessionId: $this->sessionId);
    }

    #[On('plan-done')]
    public function planDone(): void
    {
        $this->redirectRoute($this->sessionId ? 'cook.index' : 'plan.index', navigate: true);
    }

    #[On('plan-next')]
    public function planNext(): void
    {
        if ($this->sessionId) {
            $this->redirectRoute('cook.session', $this->sessionId, navigate: true);
        }
    }

    public function cooked(): void
    {
        $this->dispatch('open-cooked-panel', recipeId: $this->recipeId);
    }

    public function archive(RecipeService $recipes): void
    {
        $this->authorize('update', $this->recipe);
        $recipes->archive($this->recipe);
        unset($this->recipe);
    }

    public function restore(RecipeService $recipes): void
    {
        $this->authorize('update', $this->recipe);
        $recipes->restore($this->recipe);
        unset($this->recipe);
    }

    public function publish(RecipeService $recipes): void
    {
        $this->authorize('update', $this->recipe);
        $recipes->publish($this->recipe);
        unset($this->recipe);
        \Flux\Flux::toast(variant: 'success', text: 'Recept je verejný. Zobrazí sa na hlavnej stránke.');
    }

    public function unpublish(RecipeService $recipes): void
    {
        $this->authorize('update', $this->recipe);
        $recipes->unpublish($this->recipe);
        unset($this->recipe);
        \Flux\Flux::toast(text: 'Recept už nie je verejný.');
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    @php($recipe = $this->recipe)
    @php($liked = $this->activePerson && $recipe->preferences->contains(fn ($p) => $p->person_id === $this->activePerson->id && $p->preference === Preference::Favorite))
    @php($canEdit = auth()->user()->can('update', $recipe))

    <x-page-header :title="$recipe->title" :back="$sessionId ? route('cook.session', $sessionId) : route('recipes.index')">
        @if ($this->activePerson && ! $recipe->isArchived())
            <flux:button wire:click="toggleFavorite" variant="ghost" icon="heart" :icon:variant="$liked ? 'solid' : 'outline'" :class="$liked ? '!text-red-500' : ''" aria-label="Obľúbené" data-test="heart" />
        @endif
        <flux:dropdown align="end">
            <flux:button variant="ghost" icon="ellipsis-horizontal" aria-label="Viac" />
            <flux:menu>
                <flux:menu.item :href="route('recipes.edit', $recipe)" wire:navigate icon="pencil">Upraviť</flux:menu.item>
                <flux:menu.item :href="route('plan.history', ['recipe' => $recipe->id])" wire:navigate icon="clock">História varenia</flux:menu.item>
                @if ($canEdit)
                    <flux:menu.separator />
                    @if ($recipe->isPublic())
                        <flux:menu.item :href="route('public.recipe', $recipe)" target="_blank" icon="arrow-top-right-on-square">Otvoriť verejnú stránku</flux:menu.item>
                        <flux:menu.item wire:click="unpublish" icon="eye-slash" data-test="unpublish">Zrušiť zverejnenie</flux:menu.item>
                    @elseif (! $recipe->isArchived())
                        <flux:menu.item wire:click="publish" icon="globe-alt" data-test="publish">Zverejniť na hlavnej stránke</flux:menu.item>
                    @endif
                    <flux:menu.separator />
                    @if ($recipe->isArchived())
                        <flux:menu.item wire:click="restore" icon="arrow-uturn-left">Obnoviť z archívu</flux:menu.item>
                    @else
                        <flux:menu.item x-on:click="$flux.modal('archive-recipe').show()" icon="archive-box" data-test="archive">Archivovať</flux:menu.item>
                    @endif
                @endif
            </flux:menu>
        </flux:dropdown>
    </x-page-header>

    <x-confirm-modal name="archive-recipe" title="Archivovať recept?" text="Zostane v histórii aj v pláne, iba sa už nebude navrhovať." confirm="Archivovať" action="archive" variant="primary" icon="archive-box" />

    @if ($recipe->isArchived())
        <flux:callout icon="archive-box" variant="warning">Recept je archivovaný – nenavrhuje sa, história a plány zostávajú.</flux:callout>
    @endif

    <x-recipe-cover :recipe="$recipe" class="aspect-[4/3] w-full rounded-3xl shadow-sm" />

    <div class="space-y-3">
        @if ($recipe->description)
            <flux:text class="text-base">{{ $recipe->description }}</flux:text>
        @endif
        <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-500">
            @foreach ($recipe->mealTypeEnums() as $type)
                <flux:badge size="sm" color="orange" variant="pill">{{ $type->label() }}</flux:badge>
            @endforeach
            @if ($recipe->isPublic())
                <flux:badge size="sm" color="green" variant="pill" icon="globe-alt">Verejný</flux:badge>
            @endif
            @if ($recipe->prep_minutes)<span class="inline-flex items-center gap-1"><flux:icon name="scissors" class="size-4" /> {{ $recipe->prep_minutes }} min</span>@endif
            @if ($recipe->cook_minutes)<span class="inline-flex items-center gap-1"><flux:icon name="fire" class="size-4" /> {{ $recipe->cook_minutes }} min</span>@endif
            @if ($recipe->totalMinutes())<span class="inline-flex items-center gap-1 font-medium"><flux:icon name="clock" class="size-4" /> spolu {{ $recipe->totalMinutes() }} min</span>@endif
            @if ($recipe->side_requirement->value !== 'unknown')<span>· {{ $recipe->side_requirement->label() }}@if($recipe->included_side): {{ $recipe->included_side }}@endif</span>@endif
        </div>
        @php($likedBy = $recipe->preferences->filter(fn ($p) => $p->preference === Preference::Favorite)->map->person->filter())
        @if ($likedBy->isNotEmpty())
            <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                <flux:icon name="heart" variant="solid" class="size-4 text-red-500" /> Majú radi:
                @foreach ($likedBy as $person)<x-person-avatar :person="$person" size="size-5" />@endforeach
            </div>
        @endif
    </div>

    @unless ($recipe->isArchived())
        <div class="grid grid-cols-2 gap-3">
            <flux:button wire:click="wantToCook" variant="primary" icon="calendar-days" class="py-3" data-test="want-to-cook">Chcem variť</flux:button>
            <flux:button wire:click="cooked" icon="check" class="py-3" data-test="cooked">Uvaril som</flux:button>
        </div>
    @endunless

    @if ($recipe->ingredients->isNotEmpty())
        <flux:card class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" class="font-display">Suroviny <span class="text-sm font-normal text-zinc-500">({{ $recipe->ingredients->count() }})</span></flux:heading>
                @if ((new ServingScaler)->canScale($recipe->base_servings))
                    <div class="flex items-center gap-2 text-sm">
                        <span>Porcie:</span>
                        <flux:button size="xs" icon="minus" wire:click="$set('targetServings', {{ max(1, ($targetServings ?? 1) - 1) }})" aria-label="Menej porcií" />
                        <span class="w-6 text-center font-semibold" data-test="target-servings">{{ $targetServings }}</span>
                        <flux:button size="xs" icon="plus" wire:click="$set('targetServings', {{ ($targetServings ?? 1) + 1 }})" aria-label="Viac porcií" />
                        <span class="text-zinc-500">(recept na {{ $recipe->base_servings }})</span>
                    </div>
                @elseif ($recipe->base_servings === null)
                    <span class="text-xs text-zinc-500">Bez základných porcií sa prepočet neponúka.</span>
                @endif
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach ($this->scaledIngredients as $line)
                    <li class="flex gap-3 py-2 text-sm">
                        <span class="w-24 shrink-0 text-right font-semibold {{ $line['scaled'] ? 'text-accent' : '' }}">{{ $line['amount'] }} {{ $line['unit'] }}</span>
                        <span>{{ $line['name'] }}@if($line['note']) <span class="text-zinc-500">({{ $line['note'] }})</span>@endif</span>
                    </li>
                @endforeach
            </ul>
            @if ($targetServings !== $recipe->base_servings && (new ServingScaler)->canScale($recipe->base_servings))
                <flux:text class="text-xs text-zinc-500">Prepočítané číselné množstvá; textové („podľa chuti“) zostávajú. Originál receptu sa nemení.</flux:text>
            @endif
        </flux:card>
    @endif

    @if ($recipe->steps->isNotEmpty())
        <section class="space-y-4">
            <flux:heading size="lg" class="font-display">Postup</flux:heading>
            <ol class="space-y-5">
                @foreach ($recipe->steps as $step)
                    <li class="flex gap-4">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent font-display text-sm font-bold text-accent-foreground">{{ $loop->iteration }}</span>
                        <div class="min-w-0 flex-1 space-y-2 pt-1">
                            <p class="whitespace-pre-line text-sm leading-relaxed">{{ $step->text }}</p>
                            @php($images = $step->getMedia(App\Models\RecipeStep::IMAGES_COLLECTION))
                            @if ($images->isNotEmpty())
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($images as $image)
                                        <a href="{{ route('media.show', [$image, 'card']) }}" target="_blank"><img src="{{ route('media.show', [$image, 'thumb']) }}" class="h-24 rounded-lg object-cover" alt="Krok {{ $loop->parent->iteration }}" loading="lazy" /></a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($recipe->source || $recipe->notes)
        <div class="text-sm text-zinc-500">
            @if ($recipe->source)<div>Zdroj: {{ $recipe->source }}</div>@endif
            @if ($recipe->notes)<div class="whitespace-pre-line">{{ $recipe->notes }}</div>@endif
        </div>
    @endif

    <flux:accordion transition>
        @if ($recipe->raw_text)
            <flux:accordion.item heading="Pôvodný zápis receptu">
                <p class="whitespace-pre-line text-sm">{{ $recipe->raw_text }}</p>
            </flux:accordion.item>
        @endif
        <flux:accordion.item heading="Chute stravníkov">
            <div class="space-y-3">
                <flux:text class="text-xs text-zinc-500">Nehodnotené neznamená „nemá rád“. „Neponúkať“ je pevná výluka, ktorú generátor nikdy neobíde.</flux:text>
                <livewire:preference-editor :recipe-id="$recipe->id" :key="'prefs-'.$recipe->id" />
            </div>
        </flux:accordion.item>
    </flux:accordion>

    <section class="space-y-2">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="font-display">Naposledy varené</flux:heading>
            <flux:link :href="route('plan.history', ['recipe' => $recipe->id])" wire:navigate class="text-sm">Celá história</flux:link>
        </div>
        @forelse ($this->history as $event)
            <div class="flex items-center gap-2 text-sm">
                <span class="w-24 shrink-0">{{ $event->cooked_on->translatedFormat('j. n. Y') }}</span>
                <span class="flex -space-x-1">@foreach ($event->people as $person)<x-person-avatar :person="$person" size="size-5" />@endforeach</span>
                @if ($event->note)<span class="truncate text-zinc-500">{{ $event->note }}</span>@endif
            </div>
        @empty
            <flux:text class="text-sm">Ešte sa nevarilo.</flux:text>
        @endforelse
    </section>

    <livewire:plan-panel />
    <livewire:cooked-panel />
</div>
