@props(['recipe', 'conversion' => 'card', 'class' => ''])

@php($cover = $recipe->cover)
@if ($cover)
    <div {{ $attributes->merge(['class' => 'relative overflow-hidden bg-zinc-100 dark:bg-zinc-700 '.$class]) }}>
        <img src="{{ route('media.show', [$cover, $conversion]) }}" alt="{{ $recipe->title }}" class="size-full object-cover" loading="lazy" />
        @if ($recipe->coverIsAi())
            <span class="absolute bottom-1 right-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-medium text-white">AI ilustrácia jedla</span>
        @endif
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center p-4 text-center '.$class]) }} style="background: {{ $recipe->placeholderColor() }}22; border-color: {{ $recipe->placeholderColor() }}55;">
        <span class="line-clamp-3 text-lg font-semibold" style="color: {{ $recipe->placeholderColor() }};">{{ $recipe->title }}</span>
    </div>
@endif
