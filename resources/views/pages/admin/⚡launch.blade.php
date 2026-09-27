<?php

use App\Enums\LaunchCheckStatus;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Launch\LaunchReadiness;
use App\Services\Launch\LaunchSignoffs;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Launch checklist (v2 stage 7): automatic checks of environment, Stripe, catalogue, legal side, admin and AI, plus the
 * operator's manual confirmations. Payments are switched on by a separate deployment, never from this page.
 */
new #[Layout('layouts::admin')] #[Title('Launch checklist')] class extends Component {
    public bool $withStripe = false;

    /** @var array<string, string> note per sign-off item */
    public array $notes = [];

    /** @return list<\App\Services\Launch\LaunchCheck> */
    #[Computed]
    public function checks(): array
    {
        return app(LaunchReadiness::class)->checks($this->withStripe ? app(StripeInspector::class) : null);
    }

    /** @return array{ok: int, warn: int, fail: int, skip: int, ready: bool} */
    #[Computed]
    public function summary(): array
    {
        return app(LaunchReadiness::class)->summary($this->checks);
    }

    /** @return array<string, list<\App\Services\Launch\LaunchCheck>> */
    #[Computed]
    public function grouped(): array
    {
        return app(LaunchReadiness::class)->grouped($this->checks);
    }

    /** @return array<string, array{by: int|null, at: string, note: string}|null> */
    #[Computed]
    public function signoffs(): array
    {
        return app(LaunchSignoffs::class)->all();
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function measurement(): ?array
    {
        return app(LaunchReadiness::class)->measurement();
    }

    public function verifyStripe(): void
    {
        $this->authorize('platform-admin');
        $this->withStripe = true;
        $this->refresh();
    }

    public function confirm(string $key): void
    {
        $this->authorize('platform-admin');
        $this->resetErrorBag('notes.'.$key);
        $note = trim((string) ($this->notes[$key] ?? ''));
        if ($note === '') {
            $this->addError('notes.'.$key, __('Napíš, kto a čo overil (dátum, dokument, výsledok).'));

            return;
        }

        try {
            app(LaunchSignoffs::class)->confirm($key, auth()->user(), $note);
        } catch (InvalidArgumentException $e) {
            $this->addError('notes.'.$key, $e->getMessage());

            return;
        }

        unset($this->notes[$key]);
        $this->withStripe = false;
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Potvrdenie zapísané do auditu.'));
    }

    public function withdraw(string $key): void
    {
        $this->authorize('platform-admin');
        $this->resetErrorBag('notes.'.$key);
        $reason = trim((string) ($this->notes[$key] ?? ''));
        if ($reason === '') {
            $this->addError('notes.'.$key, __('Odvolanie potrebuje dôvod.'));

            return;
        }

        app(LaunchSignoffs::class)->withdraw($key, auth()->user(), $reason);
        unset($this->notes[$key]);
        $this->withStripe = false;
        $this->refresh();
        Flux::toast(variant: 'success', text: __('Potvrdenie odvolané; položka opäť blokuje launch.'));
    }

    private function refresh(): void
    {
        unset($this->checks, $this->summary, $this->grouped, $this->signoffs, $this->measurement);
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Launch checklist')" :subtitle="__('Čo aplikácia vie overiť sama a čo musí potvrdiť prevádzkovateľ pred zapnutím platieb. Zapnutie je samostatné nasadenie (RECIPES_CHECKOUT_ENABLED), nie tlačidlo.')" />

    @php($s = $this->summary)
    @if ($s['ready'])
        <flux:callout variant="success" icon="check-badge" data-test="launch-ready">
            <flux:callout.heading>{{ __('Bez blokujúcich položiek') }}</flux:callout.heading>
            <flux:callout.text>{{ $s['ok'] }} OK · {{ __(':count upozornení', ['count' => $s['warn']]) }} · {{ __(':count neoverených', ['count' => $s['skip']]) }}. {!! __('Platby sa zapínajú nastavením :setting v samostatnom nasadení; potom over prvý nákup v živom režime a webhook v :link.', ['setting' => '<code>RECIPES_CHECKOUT_ENABLED=true</code>', 'link' => '<a href="'.route('admin.stripe-events').'" class="underline" wire:navigate>'.__('Stripe udalostiach').'</a>']) !!}</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="launch-blocked">
            <flux:callout.heading>{{ __('Launch je blokovaný: :count položiek', ['count' => $s['fail']]) }}</flux:callout.heading>
            <flux:callout.text>{{ $s['ok'] }} OK · {{ __(':count upozornení', ['count' => $s['warn']]) }} · {{ __(':count neoverených', ['count' => $s['skip']]) }}. {!! __('Rovnaký výsledok dáva :command (exit 1, kým niečo blokuje) – vhodné do deploy pipeline.', ['command' => '<code>php artisan app:launch-check</code>']) !!}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <flux:button size="sm" variant="primary" icon="bolt" wire:click="verifyStripe" wire:loading.attr="disabled" data-test="verify-stripe">{{ __('Overiť ceny a webhook v Stripe') }}</flux:button>
        <flux:text class="text-xs">{{ __('Volá Stripe API s nakonfigurovaným kľúčom (:mode); porovná ceny s katalógom a udalosti na webhook endpointe.', ['mode' => \App\Support\StripeDashboard::isLive() ? __('živý režim') : __('sandbox')]) }}</flux:text>
    </div>

    @foreach ($this->grouped as $group => $checks)
        @continue($group === LaunchReadiness::GROUP_SIGNOFFS)
        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">{{ __($group) }}</flux:heading>
            <ul class="divide-y divide-zinc-200/70 dark:divide-zinc-800" data-test="group-{{ \Illuminate\Support\Str::slug($group) }}">
                @foreach ($checks as $check)
                    <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:gap-4">
                        <div class="sm:w-28 shrink-0"><flux:badge size="sm" :color="$check->status->color()">{{ $check->status->label() }}</flux:badge></div>
                        <div class="min-w-0 flex-1 text-sm">
                            <div class="font-medium">{{ $check->label }}</div>
                            @if ($check->detail)<div class="text-zinc-600 dark:text-zinc-400 break-words">{{ $check->detail }}</div>@endif
                            @if ($check->hint && $check->status !== LaunchCheckStatus::Ok)<div class="text-xs text-zinc-500 mt-0.5">→ {{ $check->hint }}</div>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endforeach

    <flux:card class="space-y-4" data-test="signoffs">
        <div>
            <flux:heading size="lg" class="font-display">{{ __('Ručné potvrdenia') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Rozhodnutia, ktoré aplikácia overiť nevie (zadanie kap. 7 a 18). Potvrdenie zapisuje kto, kedy a poznámku do auditu; nie je právnym posúdením.') }}</flux:text>
        </div>
        <ul class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
            @foreach (LaunchSignoffs::ITEMS as $key => $item)
                @php($confirmation = $this->signoffs[$key] ?? null)
                <li class="py-3 space-y-2" data-test="signoff-{{ $key }}">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-4">
                        <div class="sm:w-28 shrink-0"><flux:badge size="sm" :color="$confirmation ? 'green' : (LaunchSignoffs::isOptional($key) ? 'amber' : 'red')">{{ $confirmation ? __('Potvrdené') : (LaunchSignoffs::isOptional($key) ? __('Voliteľné') : __('Chýba')) }}</flux:badge></div>
                        <div class="min-w-0 flex-1 text-sm">
                            <div class="font-medium">{{ __($item['label']) }}</div>
                            <div class="text-xs text-zinc-500">{{ __($item['hint']) }}</div>
                            @if ($confirmation)
                                <div class="mt-1 text-zinc-600 dark:text-zinc-400">{{ \Carbon\CarbonImmutable::parse($confirmation['at'])->timezone(config('recipes.default_timezone'))->format('j. n. Y H:i') }} · {{ $confirmation['note'] }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:ps-32">
                        <div class="flex-1">
                            <flux:input wire:model="notes.{{ $key }}" size="sm" :placeholder="$confirmation ? __('Dôvod odvolania') : __('Kto a čo overil, dátum, dokument')" />
                            @error('notes.'.$key) <flux:text class="text-xs text-red-600 mt-1">{{ $message }}</flux:text> @enderror
                        </div>
                        @if ($confirmation)
                            <flux:button size="sm" variant="ghost" wire:click="withdraw('{{ $key }}')" wire:confirm="{{ __('Odvolať potvrdenie? Položka bude opäť blokovať launch.') }}" data-test="withdraw-{{ $key }}">{{ __('Odvolať') }}</flux:button>
                        @else
                            <flux:button size="sm" variant="primary" wire:click="confirm('{{ $key }}')" data-test="confirm-{{ $key }}">{{ __('Potvrdiť') }}</flux:button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </flux:card>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3" data-test="measurement">
            <flux:heading size="lg" class="font-display">{{ __('Meranie AI nákladov') }}</flux:heading>
            @php($m = $this->measurement)
            @if ($m === null)
                <flux:text class="text-sm">{{ __('Zatiaľ nemerané.') }} {!! __('Na reálnom kľúči spusti :command (predvolene 30 textov + 30 obrázkov); výsledok sa zobrazí tu.', ['command' => '<code>php artisan app:ai-measure &lt;domácnosť&gt; --yes</code>']) !!}</flux:text>
            @else
                <flux:text class="text-xs">{{ __('Beh :run', ['run' => $m['run']]) }} · {{ \Carbon\CarbonImmutable::parse($m['at'])->timezone(config('recipes.default_timezone'))->format('j. n. Y H:i') }} · {{ __('spolu :cost (ceny poskytovateľa v USD)', ['cost' => Money::microUsd((int) $m['total_cost_micro'])]) }}</flux:text>
                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                    @foreach ($m['kinds'] as $kind => $k)
                        <dt class="text-zinc-500">{{ $kind === 'text' ? __('Text') : __('Obrázok') }} · {{ $k['model'] ?? '?' }} · {{ __(':succeeded/:jobs doručených', ['succeeded' => $k['succeeded'], 'jobs' => $k['jobs']]) }}</dt>
                        <dd class="text-right tabular-nums">Ø {{ $k['avg_cost_micro'] !== null ? Money::microUsd($k['avg_cost_micro']) : '–' }}</dd>
                    @endforeach
                    <dt class="font-medium">{{ __('Plný mesiac Plus (30 + 5) vs. 2,49 € / 2,00 €') }}</dt>
                    <dd class="text-right font-medium tabular-nums">{{ $m['projection']['plus_month_micro'] !== null ? Money::microUsd($m['projection']['plus_month_micro'], 2) : '–' }}</dd>
                    <dt class="text-zinc-500">{{ __('20 obrázkov vs. 3,99 €') }}</dt>
                    <dd class="text-right tabular-nums">{{ $m['projection']['images_20_micro'] !== null ? Money::microUsd($m['projection']['images_20_micro'], 2) : '–' }}</dd>
                    <dt class="text-zinc-500">{{ __('100 textov vs. 1,99 €') }}</dt>
                    <dd class="text-right tabular-nums">{{ $m['projection']['text_100_micro'] !== null ? Money::microUsd($m['projection']['text_100_micro'], 2) : '–' }}</dd>
                </dl>
            @endif
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">{{ __('Simulácia a nasadenie') }}</flux:heading>
            <flux:text class="text-sm">{!! __('Sandbox: :start, potom :advance a :status.', ['start' => '<code>php artisan app:billing-test-clock start &lt;domácnosť&gt; --plan=plus_yearly --at="2027-01-31 10:00"</code>', 'advance' => '<code>advance clock_… --at="+1 month"</code>', 'status' => '<code>status clock_… --sync</code>']) !!} {{ __('Webhooky musia doraziť do tejto inštancie.') }}</flux:text>
            <flux:text class="text-sm">{!! __('Produkcia: :webhook vytvorí endpoint s presným zoznamom udalostí; :check v deploy pipeline.', ['webhook' => '<code>php artisan cashier:webhook</code>', 'check' => '<code>php artisan app:launch-check --stripe</code>']) !!} {!! __('Postup krok za krokom je v :file.', ['file' => '<code>docs/runbook-launch.md</code>']) !!}</flux:text>
        </flux:card>
    </div>
</div>
