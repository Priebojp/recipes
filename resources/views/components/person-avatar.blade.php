@props(['person', 'size' => 'size-7'])

<span {{ $attributes->merge(['class' => "inline-flex {$size} shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"]) }}
      style="background: {{ $person->colorOrDefault() }};" title="{{ $person->name }}" aria-label="{{ $person->name }}">
    {{ $person->initials() }}
</span>
