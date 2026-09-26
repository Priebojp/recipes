<?php

use App\Models\Recipe;
use App\Models\RecipeStep;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::public')] class extends Component {
    public int $recipeId;

    public function mount(Recipe $recipe): void
    {
        abort_unless($recipe->isPublic(), 404);
        $this->recipeId = $recipe->id;
    }

    #[Computed]
    public function recipe(): Recipe
    {
        return Recipe::query()->published()->with(['cover', 'mealTypes', 'ingredients', 'steps.media'])->findOrFail($this->recipeId);
    }

    public function title(): string
    {
        return $this->recipe->title;
    }
}; ?>

<div class="mx-auto max-w-4xl space-y-8">
    @php($recipe = $this->recipe)

    <flux:button :href="route('home')" wire:navigate variant="ghost" icon="chevron-left" size="sm">Všetky verejné recepty</flux:button>

    <div class="grid gap-8 md:grid-cols-5">
        <x-recipe-cover :recipe="$recipe" class="aspect-[4/3] w-full rounded-3xl shadow-sm md:col-span-3" />
        <div class="space-y-4 md:col-span-2">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($recipe->mealTypeEnums() as $type)
                    <flux:badge color="orange" variant="pill">{{ $type->label() }}</flux:badge>
                @endforeach
            </div>
            <h1 class="font-display text-3xl font-bold leading-tight sm:text-4xl">{{ $recipe->title }}</h1>
            @if ($recipe->description)
                <flux:text class="text-base">{{ $recipe->description }}</flux:text>
            @endif
            <dl class="grid grid-cols-2 gap-3 text-sm">
                @if ($recipe->prep_minutes)
                    <div class="rounded-xl bg-white p-3 dark:bg-zinc-800"><dt class="text-zinc-500">Príprava</dt><dd class="font-semibold">{{ $recipe->prep_minutes }} min</dd></div>
                @endif
                @if ($recipe->cook_minutes)
                    <div class="rounded-xl bg-white p-3 dark:bg-zinc-800"><dt class="text-zinc-500">Varenie</dt><dd class="font-semibold">{{ $recipe->cook_minutes }} min</dd></div>
                @endif
                @if ($recipe->base_servings)
                    <div class="rounded-xl bg-white p-3 dark:bg-zinc-800"><dt class="text-zinc-500">Porcie</dt><dd class="font-semibold">{{ $recipe->base_servings }}</dd></div>
                @endif
                @if ($recipe->side_requirement->value !== 'unknown')
                    <div class="rounded-xl bg-white p-3 dark:bg-zinc-800"><dt class="text-zinc-500">Príloha</dt><dd class="font-semibold">{{ $recipe->included_side ?: $recipe->side_requirement->label() }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    <div class="grid gap-8 md:grid-cols-5">
        @if ($recipe->ingredients->isNotEmpty())
            <flux:card class="h-fit space-y-3 md:col-span-2">
                <flux:heading size="lg" class="font-display">Suroviny</flux:heading>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach ($recipe->ingredients as $line)
                        <li class="flex gap-3 py-2 text-sm">
                            <span class="w-20 shrink-0 text-right font-semibold text-accent">{{ $line->displayAmount() }} {{ $line->unit }}</span>
                            <span>{{ $line->name }}@if($line->note) <span class="text-zinc-500">({{ $line->note }})</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif

        @if ($recipe->steps->isNotEmpty())
            <section class="space-y-4 md:col-span-3">
                <flux:heading size="lg" class="font-display">Postup</flux:heading>
                <ol class="space-y-5">
                    @foreach ($recipe->steps as $step)
                        <li class="flex gap-4">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent font-display text-sm font-bold text-accent-foreground">{{ $loop->iteration }}</span>
                            <div class="min-w-0 flex-1 space-y-2 pt-1">
                                <p class="whitespace-pre-line text-sm leading-relaxed">{{ $step->text }}</p>
                                @php($images = $step->getMedia(RecipeStep::IMAGES_COLLECTION))
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
    </div>

    @if ($recipe->ingredients->isEmpty() && $recipe->steps->isEmpty() && $recipe->raw_text)
        <flux:card>
            <flux:heading size="lg" class="font-display">Recept</flux:heading>
            <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $recipe->raw_text }}</p>
        </flux:card>
    @endif

    @if ($recipe->source)
        <flux:text class="text-sm">Zdroj: {{ $recipe->source }}</flux:text>
    @endif
</div>
