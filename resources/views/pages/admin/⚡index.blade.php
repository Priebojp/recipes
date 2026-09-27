<?php

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReport;
use App\Services\Billing\Catalog;
use App\Services\Billing\FinanceReport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('Administrácia – prehľad')] class extends Component {
    /** @return array<string, mixed> */
    #[Computed]
    public function stats(): array
    {
        $report = app(AiUsageReport::class);
        $now = CarbonImmutable::now($report->timezone());

        return [
            'households' => Household::query()->count(),
            'users' => User::query()->count(),
            'admins' => User::query()->where('is_platform_admin', true)->count(),
            'recipes' => Recipe::query()->count(),
            'today' => $report->summary($now->startOfDay(), $now->endOfDay()),
            'month' => $report->summary($now->startOfMonth(), $now->endOfMonth()),
            'days30' => $report->summary($now->subDays(29)->startOfDay(), $now->endOfDay()),
            'budget' => $report->budget(),
            'queue_pending' => DB::table('jobs')->count(),
            'queue_failed' => DB::table('failed_jobs')->count(),
            'ai_failed_24h' => AiJob::query()->where('status', AiJobStatus::Failed)->where('created_at', '>=', now()->subDay())->count(),
            'ai_active' => AiJob::query()->whereIn('status', [AiJobStatus::Queued, AiJobStatus::Running])->count(),
        ];
    }

    /** @return array{recurring: array<string, mixed>, month: array<string, mixed>, previous: array<string, mixed>, attention: array<string, int>, month_label: string} */
    #[Computed]
    public function finance(): array
    {
        $report = app(FinanceReport::class);
        $now = CarbonImmutable::now($report->timezone());

        return [
            'recurring' => $report->recurring(),
            'month' => $report->period($now->startOfMonth(), $now->endOfMonth()),
            'previous' => $report->period($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()),
            'attention' => $report->attention(),
            'month_label' => $now->format('n/Y'),
        ];
    }

    #[Computed]
    public function settings(): AiSettings
    {
        return app(AiSettings::class);
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Prehľad')" :subtitle="__('Predplatné, inkaso, refundácie, AI náklady a fronta. Tržba, inkaso a náklady sú oddelené; príspevok je odhad, nie zisk.')" />

    @php($s = $this->stats)
    @php($f = $this->finance)
    @php($att = $f['attention'])
    @php($needsAttention = array_sum($att) > 0)

    @if ($needsAttention)
        <flux:callout variant="warning" icon="bell-alert">
            <flux:callout.heading>{{ __('Vyžaduje pozornosť') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc space-y-0.5 ps-4">
                    @if ($att['webhooks_failed'] > 0)<li><a href="{{ route('admin.stripe-events', ['state' => 'failed']) }}" class="underline" wire:navigate>{{ __(':count zlyhaných Stripe udalostí', ['count' => $att['webhooks_failed']]) }}</a></li>@endif
                    @if ($att['webhooks_received'] > 0)<li>{!! __(':link (beží fronta?)', ['link' => '<a href="'.route('admin.stripe-events', ['state' => 'received']).'" class="underline" wire:navigate>'.__(':count Stripe udalostí čaká na spracovanie dlhšie ako hodinu', ['count' => $att['webhooks_received']]).'</a>']) !!}</li>@endif
                    @if ($att['refunds_to_review'] > 0)<li><a href="{{ route('admin.refunds', ['status' => 'needs_review']) }}" class="underline" wire:navigate>{{ __(':count refundácií zo Stripe čaká na posúdenie', ['count' => $att['refunds_to_review']]) }}</a></li>@endif
                    @if ($att['disputes'] > 0)<li><a href="{{ route('admin.refunds', ['status' => 'disputed']) }}" class="underline" wire:navigate>{{ __(':count otvorených sporov o platbu', ['count' => $att['disputes']]) }}</a></li>@endif
                    @if ($att['ai_reconciling'] > 0)<li><a href="{{ route('admin.ai', ['status' => 'reconciling']) }}" class="underline" wire:navigate>{{ __(':count AI úloh s nejasným výsledkom', ['count' => $att['ai_reconciling']]) }}</a> (<code>php artisan app:ai-reconcile</code>)</li>@endif
                    @if ($att['stale_reservations'] > 0)<li><a href="{{ route('admin.usage', ['open' => 1]) }}" class="underline" wire:navigate>{{ __(':count rezervácií použití držaných dlhšie ako deň', ['count' => $att['stale_reservations']]) }}</a></li>@endif
                    @if ($att['pending_orders'] > 0)<li>{!! __(':link (uzavrú sa po 24 h)', ['link' => '<a href="'.route('admin.orders', ['status' => 'pending']).'" class="underline" wire:navigate>'.__(':count objednávok čaká na úhradu dlhšie ako hodinu', ['count' => $att['pending_orders']]).'</a>']) !!}</li>@endif
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="finance-cards">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Platiace domácnosti') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ $f['recurring']['paying_households'] }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count mesačne', ['count' => $f['recurring']['monthly']]) }} · {{ __(':count ročne', ['count' => $f['recurring']['yearly']]) }} @if ($f['recurring']['compensation'] > 0) · {{ __(':count kompenzačný Plus', ['count' => $f['recurring']['compensation']]) }} @endif @if ($f['recurring']['canceling'] > 0) · {{ __(':count bez obnovy', ['count' => $f['recurring']['canceling']]) }} @endif</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('MRR (normalizované)') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Catalog::formatCents($f['recurring']['mrr_cents']) }}</flux:heading>
            <flux:text class="text-xs">{{ __('Ročné platby ÷ 12; konečné ceny bez rozlíšenia DPH režimu') }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Inkaso :month', ['month' => $f['month_label']]) }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Catalog::formatCents($f['month']['cash_cents']) }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count úhrad', ['count' => $f['month']['orders_paid']]) }} · {{ __('predplatné :amount', ['amount' => Catalog::formatCents($f['month']['subscriptions_cents'])]) }} · {{ __('balíky :amount', ['amount' => Catalog::formatCents($f['month']['addons_cents'])]) }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Refundácie :month', ['month' => $f['month_label']]) }}</flux:text>
            <flux:heading size="xl" class="font-display {{ $f['month']['refunds_cents'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Catalog::formatCents($f['month']['refunds_cents']) }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count prípadov', ['count' => $f['month']['refunds_count']]) }} · {{ __('minulý mesiac :amount', ['amount' => Catalog::formatCents($f['previous']['refunds_cents'])]) }}</flux:text>
        </flux:card>
    </div>

    <flux:card class="space-y-3">
        <flux:heading size="lg" class="font-display">{{ __('Ekonomika mesiaca :month', ['month' => $f['month_label']]) }}</flux:heading>
        <div class="grid gap-x-8 gap-y-3 text-sm lg:grid-cols-[3fr_2fr]">
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                <dt class="text-zinc-500">{{ __('Tržba (časovo rozlíšená)') }}</dt><dd class="text-right tabular-nums">{{ Catalog::formatCents($f['month']['revenue_cents']) }}</dd>
                <dt class="text-zinc-500">{{ __('Inkaso (cash)') }}</dt><dd class="text-right tabular-nums">{{ Catalog::formatCents($f['month']['cash_cents']) }}</dd>
                <dt class="text-zinc-500">{{ __('Refundácie') }}</dt><dd class="text-right tabular-nums">− {{ Catalog::formatCents($f['month']['refunds_cents']) }}</dd>
                <dt class="text-zinc-500">{{ __('AI náklady (odhad, :count úloh)', ['count' => $f['month']['ai_jobs']]) }}</dt><dd class="text-right tabular-nums">{{ Money::microUsd($f['month']['ai_cost_micro'], 2) }}@if ($f['month']['ai_cost_cents'] !== null) ≈ {{ Catalog::formatCents($f['month']['ai_cost_cents']) }}@endif</dd>
                <dt class="font-medium">{{ __('Príspevok po variabilných nákladoch') }}</dt>
                <dd class="text-right font-medium tabular-nums">
                    @if ($f['month']['contribution_cents'] !== null)
                        {{ Catalog::formatCents($f['month']['contribution_cents']) }}
                    @else
                        <span class="font-normal text-zinc-500" title="RECIPES_BILLING_USD_EUR_RATE">{{ __('bez kurzu USD→EUR') }}</span>
                    @endif
                </dd>
            </dl>
            <flux:text class="text-xs">
                {{ __('Tržba rozpočítava zaplatené obdobia na dni mesiaca (ročná platba nie je mesačný výnos); inkaso je to, čo prišlo.') }}
                @if ($f['month']['usd_eur_rate'] !== null) {{ __('AI náklady sú odhad z cenníka poskytovateľa v USD prepočítaný kurzom :rate.', ['rate' => $f['month']['usd_eur_rate']]) }} @else {{ __('AI náklady sú odhad z cenníka poskytovateľa v USD.') }} @endif
                {{ __('Príspevok = inkaso − refundácie − AI náklady; nezahŕňa poplatky Stripe, dane ani fixné náklady, preto nie je čistý zisk.') }}
                {{ __('Minulý mesiac: inkaso :cash, tržba :revenue.', ['cash' => Catalog::formatCents($f['previous']['cash_cents']), 'revenue' => Catalog::formatCents($f['previous']['revenue_cents'])]) }}
            </flux:text>
        </div>
    </flux:card>

    @unless ($this->settings->enabled())
        <flux:callout variant="danger" icon="power">
            <flux:callout.heading>{{ __('AI je globálne vypnuté (kill switch)') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Nové AI úlohy sa nespúšťajú, dáta zostávajú.') }} {!! __('Zapnúť ho možno v :link.', ['link' => '<a href="'.route('admin.ai.settings').'" class="underline" wire:navigate>'.__('AI nastaveniach').'</a>']) !!}</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($s['budget']['exceeded'])
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Mesačný AI rozpočet je prekročený') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Odhad :spent z rozpočtu :budget za :month.', ['spent' => Money::microUsd($s['budget']['spent_micro']), 'budget' => Money::microUsd($s['budget']['budget_micro'], 2), 'month' => $s['budget']['month']]) }} {{ __('Rozpočet je iba alarm – zaplatené použitia sa neskracujú.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Domácnosti') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ number_format($s['households'], 0, ',', ' ') }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count účtov', ['count' => $s['users']]) }} · {{ __(':count receptov', ['count' => $s['recipes']]) }} · {{ __(':count admin', ['count' => $s['admins']]) }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('AI náklady dnes') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['today']['cost_micro']) }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count úloh', ['count' => $s['today']['jobs']]) }} · {{ __(':count chýb', ['count' => $s['today']['failed']]) }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('AI náklady :month', ['month' => $s['budget']['month']]) }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['month']['cost_micro']) }}</flux:heading>
            @if ($s['budget']['budget_micro'] !== null)
                @php($pct = min(100, (int) round(($s['budget']['ratio'] ?? 0) * 100)))
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full {{ $s['budget']['exceeded'] ? 'bg-red-500' : 'bg-accent' }}" style="width: {{ $pct }}%"></div>
                </div>
                <flux:text class="text-xs">{{ __(':percent % z rozpočtu :budget', ['percent' => $pct, 'budget' => Money::microUsd($s['budget']['budget_micro'], 2)]) }}</flux:text>
            @else
                <flux:text class="text-xs">{{ __('Rozpočet nie je nastavený') }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Posledných 30 dní') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['days30']['cost_micro']) }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count doručených', ['count' => $s['days30']['succeeded']]) }} · {{ __(':count chýb', ['count' => $s['days30']['failed']]) }} @if ($s['days30']['unpriced'] > 0) · {{ __(':count bez ceny', ['count' => $s['days30']['unpriced']]) }} @endif</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">{{ __('AI prevádzka') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-zinc-500">{{ __('Stav') }}</dt>
                <dd>{!! $this->settings->enabled() ? '<span class="text-green-700 dark:text-green-400">'.__('zapnuté').'</span>' : '<span class="text-red-600">'.__('vypnuté').'</span>' !!}</dd>
                <dt class="text-zinc-500">{{ __('Textový model') }}</dt>
                <dd>{{ $this->settings->textModel() ?? __('predvolený poskytovateľa') }} · {{ __('effort :effort', ['effort' => $this->settings->textReasoningEffort()]) }}</dd>
                <dt class="text-zinc-500">{{ __('Obrázkový model') }}</dt>
                <dd>{{ $this->settings->imageModel() ?? __('predvolený poskytovateľa') }} · {{ __('predvolený profil :profile', ['profile' => $this->settings->defaultImageProfile()->label()]) }} ({{ $this->settings->imageQuality() }} · {{ $this->settings->imagePixelSize() }})</dd>
                <dt class="text-zinc-500">{{ __('Bežiace úlohy') }}</dt>
                <dd>{{ $s['ai_active'] }}</dd>
                <dt class="text-zinc-500">{{ __('Chyby za 24 h') }}</dt>
                <dd class="{{ $s['ai_failed_24h'] > 0 ? 'text-red-600' : '' }}">{{ $s['ai_failed_24h'] }}</dd>
            </dl>
            <div class="flex gap-2">
                <flux:button size="sm" :href="route('admin.ai')" wire:navigate icon="cpu-chip">{{ __('Použitie a náklady') }}</flux:button>
                <flux:button size="sm" variant="ghost" :href="route('admin.ai.settings')" wire:navigate icon="adjustments-horizontal">{{ __('Nastavenia') }}</flux:button>
            </div>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">{{ __('Fronta') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-zinc-500">{{ __('Čakajúce úlohy') }}</dt>
                <dd>{{ $s['queue_pending'] }}</dd>
                <dt class="text-zinc-500">{{ __('Zlyhané úlohy (failed_jobs)') }}</dt>
                <dd class="{{ $s['queue_failed'] > 0 ? 'text-red-600' : '' }}">{{ $s['queue_failed'] }}</dd>
            </dl>
            <flux:text class="text-xs">{!! __('Zlyhané úlohy fronty sa riešia cez :command; AI úlohy majú jediný pokus, opakovanie je vedomá akcia používateľa.', ['command' => '<code>php artisan queue:retry</code>']) !!}</flux:text>
        </flux:card>
    </div>
</div>
