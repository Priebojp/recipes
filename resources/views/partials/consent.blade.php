{{-- Cookie consent (specification chapters 10 and 12). The server embeds only the loaders of services the visitor
     already granted under the current policy version; the banner appears only when there is something optional
     to decide. Closing or scrolling is never consent. --}}
@php
    $consentPolicy = app(App\Services\Consent\ConsentPolicy::class);
    $consentConfig = $consentPolicy->clientConfig(request());
    $consentBanner = $consentPolicy->bannerNeeded(request());
@endphp
<script type="application/json" id="mr-consent-config">@json($consentConfig)</script>

@if ($consentConfig['offered'] !== [])
    <div x-data="mrConsent({{ $consentBanner ? 'true' : 'false' }})"
         x-on:mr-consent-open.window="openSettings()"
         x-on:keydown.escape.window="settingsOpen = false"
         data-test="consent-banner-root">
        {{-- Banner: rendered only while a decision is missing; the settings panel below is always available. --}}
        @if ($consentBanner)
        <div x-show="banner && !settingsOpen" x-cloak x-transition
             class="fixed inset-x-3 bottom-20 z-40 mx-auto max-w-2xl rounded-2xl border border-zinc-200 bg-paper p-4 shadow-lg sm:bottom-6 sm:p-5 dark:border-zinc-700 dark:bg-zinc-900"
             role="dialog" aria-modal="false" aria-labelledby="mr-consent-title" data-test="consent-banner">
            <h2 id="mr-consent-title" class="font-display text-lg font-semibold text-zinc-900 dark:text-white">Cookies a voliteľné služby</h2>
            <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                Na fungovanie účtu a zabezpečenie používame nevyhnutné technológie. S vaším súhlasom použijeme aj analytiku na meranie používania aplikácie. Voľbu môžete kedykoľvek zmeniť v nastaveniach cookies.
                <a href="{{ route('legal.show', ['slug' => 'cookies']) }}" class="underline">Viac o cookies</a>
            </p>
            <div class="mt-4 grid gap-2 sm:grid-cols-3">
                <flux:button variant="primary" size="sm" x-on:click="decide('accept_all')" data-test="consent-accept">Prijať voliteľné</flux:button>
                <flux:button variant="primary" size="sm" x-on:click="decide('reject_all')" data-test="consent-reject">Odmietnuť voliteľné</flux:button>
                <flux:button variant="filled" size="sm" x-on:click="openSettings()" data-test="consent-settings">Nastavenia</flux:button>
            </div>
        </div>
        @endif

        {{-- Settings panel --}}
        <div x-show="settingsOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-3 sm:items-center" x-on:click.self="settingsOpen = false">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 bg-paper p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="mr-consent-settings-title" data-test="consent-panel">
                <h2 id="mr-consent-settings-title" class="font-display text-lg font-semibold text-zinc-900 dark:text-white">Nastavenia cookies</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Nevyhnutné technológie bežia vždy; voliteľné iba po vašom súhlase. Nič nie je vopred zapnuté.</p>

                <ul class="mt-4 space-y-3">
                    <li class="flex items-start justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div>
                            <div class="font-medium">{{ App\Enums\ConsentCategory::Necessary->label() }}</div>
                            <div class="text-xs text-zinc-500">{{ App\Enums\ConsentCategory::Necessary->description() }}</div>
                        </div>
                        <span class="text-xs text-zinc-500">vždy</span>
                    </li>
                    <template x-for="category in offered" :key="category.key">
                        <li class="flex items-start justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div>
                                <div class="font-medium" x-text="category.label"></div>
                                <div class="text-xs text-zinc-500" x-text="category.description"></div>
                            </div>
                            <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" class="size-4 rounded border-zinc-300 text-accent focus:ring-accent" x-model="choices[category.key]" :data-test="'consent-toggle-' + category.key">
                                <span x-text="choices[category.key] ? 'zapnuté' : 'vypnuté'"></span>
                            </label>
                        </li>
                    </template>
                </ul>

                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <flux:button variant="ghost" size="sm" x-on:click="settingsOpen = false">Zavrieť</flux:button>
                    <flux:button variant="filled" size="sm" x-on:click="decide('reject_all')" data-test="consent-panel-reject">Odmietnuť voliteľné</flux:button>
                    <flux:button variant="primary" size="sm" x-on:click="decide('custom')" data-test="consent-panel-save">Uložiť voľbu</flux:button>
                </div>
                <p class="mt-3 text-xs text-zinc-500">Odvolanie súhlasu zastaví ďalšie odosielanie a odstráni identifikátory, ktoré spravujeme. <a href="{{ route('legal.show', ['slug' => 'cookies']) }}" class="underline">Zoznam služieb</a></p>
            </div>
        </div>
    </div>
@endif
