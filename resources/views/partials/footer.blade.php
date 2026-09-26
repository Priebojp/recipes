{{-- Legal links shared by the public, application and auth layouts. The cookie settings link opens the consent
     panel when optional services exist; otherwise it leads to the cookies page (no empty consent is asked). --}}
@php
    $consentPolicy = app(App\Services\Consent\ConsentPolicy::class);
    $operator = app(App\Services\Legal\OperatorIdentity::class);
    $hasOptional = $consentPolicy->offeredCategories() !== [];
@endphp
<footer class="mx-auto w-full max-w-6xl px-4 pb-10 pt-6 text-sm text-zinc-500 sm:px-6 dark:text-zinc-400 {{ $class ?? '' }}" data-test="legal-footer">
    <nav class="flex flex-wrap justify-center gap-x-4 gap-y-1" aria-label="Právne informácie">
        <a href="{{ route('legal.show', ['slug' => 'vop']) }}" wire:navigate class="hover:underline">Obchodné podmienky</a>
        <a href="{{ route('legal.show', ['slug' => 'ochrana-osobnych-udajov']) }}" wire:navigate class="hover:underline">Ochrana osobných údajov</a>
        <a href="{{ route('legal.show', ['slug' => 'cookies']) }}" wire:navigate class="hover:underline">Cookies</a>
        @if ($hasOptional)
            <button type="button" class="hover:underline" data-consent-open>Nastavenia cookies</button>
        @endif
        <a href="{{ route('legal.show', ['slug' => 'odstupenie-od-zmluvy']) }}" wire:navigate class="hover:underline">Odstúpenie od zmluvy</a>
        <a href="{{ route('legal.contact') }}" wire:navigate class="hover:underline">Kontakt a reklamácie</a>
    </nav>
    <p class="mt-3 text-center text-xs">
        {{ config('app.name') }} · rodinná kuchárka
        @if ($operator->get('business_name') !== '')
            · {{ $operator->get('business_name') }}
        @endif
    </p>
</footer>
