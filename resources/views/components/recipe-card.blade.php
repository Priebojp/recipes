@props(['recipe', 'href'])

<article {{ $attributes->merge(['class' => 'group relative flex flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700/80 dark:bg-zinc-800']) }}>
    <a href="{{ $href }}" wire:navigate class="flex flex-1 flex-col">
        <x-recipe-cover :recipe="$recipe" conversion="thumb" class="aspect-[4/3] w-full [&_img]:transition [&_img]:duration-500 group-hover:[&_img]:scale-105" />
        <div class="flex flex-1 flex-col gap-1.5 p-3.5">
            <h3 class="line-clamp-2 font-display text-base font-semibold leading-snug text-zinc-900 dark:text-white">{{ $recipe->title }}</h3>
            @if ($recipe->description)
                <p class="line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $recipe->description }}</p>
            @endif
            <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-2">
                @foreach ($recipe->mealTypeEnums() as $type)
                    <flux:badge size="sm" color="orange" variant="pill">{{ $type->label() }}</flux:badge>
                @endforeach
                @if ($recipe->totalMinutes())
                    <span class="inline-flex items-center gap-1 text-xs text-zinc-500"><flux:icon name="clock" class="size-3.5" /> {{ $recipe->totalMinutes() }} min</span>
                @endif
                {{ $meta ?? '' }}
            </div>
        </div>
    </a>
    {{ $slot }}
</article>
