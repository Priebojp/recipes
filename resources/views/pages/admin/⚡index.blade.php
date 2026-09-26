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
    <x-page-header title="Prehľad" subtitle="Predplatné, inkaso, refundácie, AI náklady a fronta. Tržba, inkaso a náklady sú oddelené; príspevok je odhad, nie zisk." />

    @php($s = $this->stats)
    @php($f = $this->finance)
    @php($att = $f['attention'])
    @php($needsAttention = array_sum($att) > 0)

    @if ($needsAttention)
        <flux:callout variant="warning" icon="bell-alert">
            <flux:callout.heading>Vyžaduje pozornosť</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-disc space-y-0.5 ps-4">
                    @if ($att['webhooks_failed'] > 0)<li><a href="{{ route('admin.stripe-events', ['state' => 'failed']) }}" class="underline" wire:navigate>{{ $att['webhooks_failed'] }} zlyhaných Stripe udalostí</a></li>@endif
                    @if ($att['webhooks_received'] > 0)<li><a href="{{ route('admin.stripe-events', ['state' => 'received']) }}" class="underline" wire:navigate>{{ $att['webhooks_received'] }} Stripe udalostí čaká na spracovanie dlhšie ako hodinu</a> (beží fronta?)</li>@endif
                    @if ($att['refunds_to_review'] > 0)<li><a href="{{ route('admin.refunds', ['status' => 'needs_review']) }}" class="underline" wire:navigate>{{ $att['refunds_to_review'] }} refundácií zo Stripe čaká na posúdenie</a></li>@endif
                    @if ($att['disputes'] > 0)<li><a href="{{ route('admin.refunds', ['status' => 'disputed']) }}" class="underline" wire:navigate>{{ $att['disputes'] }} otvorených sporov o platbu</a></li>@endif
                    @if ($att['ai_reconciling'] > 0)<li><a href="{{ route('admin.ai', ['status' => 'reconciling']) }}" class="underline" wire:navigate>{{ $att['ai_reconciling'] }} AI úloh s nejasným výsledkom</a> (<code>php artisan app:ai-reconcile</code>)</li>@endif
                    @if ($att['stale_reservations'] > 0)<li><a href="{{ route('admin.usage', ['open' => 1]) }}" class="underline" wire:navigate>{{ $att['stale_reservations'] }} rezervácií použití držaných dlhšie ako deň</a></li>@endif
                    @if ($att['pending_orders'] > 0)<li><a href="{{ route('admin.orders', ['status' => 'pending']) }}" class="underline" wire:navigate>{{ $att['pending_orders'] }} objednávok čaká na úhradu dlhšie ako hodinu</a> (uzavrú sa po 24 h)</li>@endif
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="finance-cards">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Platiace domácnosti</flux:text>
            <flux:heading size="xl" class="font-display">{{ $f['recurring']['paying_households'] }}</flux:heading>
            <flux:text class="text-xs">{{ $f['recurring']['monthly'] }} mesačne · {{ $f['recurring']['yearly'] }} ročne @if ($f['recurring']['compensation'] > 0) · {{ $f['recurring']['compensation'] }} kompenzačný Plus @endif @if ($f['recurring']['canceling'] > 0) · {{ $f['recurring']['canceling'] }} bez obnovy @endif</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">MRR (normalizované)</flux:text>
            <flux:heading size="xl" class="font-display">{{ Catalog::formatCents($f['recurring']['mrr_cents']) }}</flux:heading>
            <flux:text class="text-xs">Ročné platby ÷ 12; konečné ceny bez rozlíšenia DPH režimu</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Inkaso {{ $f['month_label'] }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Catalog::formatCents($f['month']['cash_cents']) }}</flux:heading>
            <flux:text class="text-xs">{{ $f['month']['orders_paid'] }} úhrad · predplatné {{ Catalog::formatCents($f['month']['subscriptions_cents']) }} · balíky {{ Catalog::formatCents($f['month']['addons_cents']) }}</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Refundácie {{ $f['month_label'] }}</flux:text>
            <flux:heading size="xl" class="font-display {{ $f['month']['refunds_cents'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ Catalog::formatCents($f['month']['refunds_cents']) }}</flux:heading>
            <flux:text class="text-xs">{{ $f['month']['refunds_count'] }} prípadov · minulý mesiac {{ Catalog::formatCents($f['previous']['refunds_cents']) }}</flux:text>
        </flux:card>
    </div>

    <flux:card class="space-y-3">
        <flux:heading size="lg" class="font-display">Ekonomika mesiaca {{ $f['month_label'] }}</flux:heading>
        <div class="grid gap-x-8 gap-y-3 text-sm lg:grid-cols-[3fr_2fr]">
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                <dt class="text-zinc-500">Tržba (časovo rozlíšená)</dt><dd class="text-right tabular-nums">{{ Catalog::formatCents($f['month']['revenue_cents']) }}</dd>
                <dt class="text-zinc-500">Inkaso (cash)</dt><dd class="text-right tabular-nums">{{ Catalog::formatCents($f['month']['cash_cents']) }}</dd>
                <dt class="text-zinc-500">Refundácie</dt><dd class="text-right tabular-nums">− {{ Catalog::formatCents($f['month']['refunds_cents']) }}</dd>
                <dt class="text-zinc-500">AI náklady (odhad, {{ $f['month']['ai_jobs'] }} úloh)</dt><dd class="text-right tabular-nums">{{ Money::microUsd($f['month']['ai_cost_micro'], 2) }}@if ($f['month']['ai_cost_cents'] !== null) ≈ {{ Catalog::formatCents($f['month']['ai_cost_cents']) }}@endif</dd>
                <dt class="font-medium">Príspevok po variabilných nákladoch</dt>
                <dd class="text-right font-medium tabular-nums">
                    @if ($f['month']['contribution_cents'] !== null)
                        {{ Catalog::formatCents($f['month']['contribution_cents']) }}
                    @else
                        <span class="font-normal text-zinc-500" title="RECIPES_BILLING_USD_EUR_RATE">bez kurzu USD→EUR</span>
                    @endif
                </dd>
            </dl>
            <flux:text class="text-xs">
                Tržba rozpočítava zaplatené obdobia na dni mesiaca (ročná platba nie je mesačný výnos); inkaso je to, čo prišlo. AI náklady sú odhad z cenníka poskytovateľa v USD
                @if ($f['month']['usd_eur_rate'] !== null) prepočítaný kurzom {{ $f['month']['usd_eur_rate'] }} @endif.
                Príspevok = inkaso − refundácie − AI náklady; nezahŕňa poplatky Stripe, dane ani fixné náklady, preto nie je čistý zisk.
                Minulý mesiac: inkaso {{ Catalog::formatCents($f['previous']['cash_cents']) }}, tržba {{ Catalog::formatCents($f['previous']['revenue_cents']) }}.
            </flux:text>
        </div>
    </flux:card>

    @unless ($this->settings->enabled())
        <flux:callout variant="danger" icon="power">
            <flux:callout.heading>AI je globálne vypnuté (kill switch)</flux:callout.heading>
            <flux:callout.text>Nové AI úlohy sa nespúšťajú, dáta zostávajú. Zapnúť ho možno v <a href="{{ route('admin.ai.settings') }}" class="underline" wire:navigate>AI nastaveniach</a>.</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($s['budget']['exceeded'])
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>Mesačný AI rozpočet je prekročený</flux:callout.heading>
            <flux:callout.text>Odhad {{ Money::microUsd($s['budget']['spent_micro']) }} z rozpočtu {{ Money::microUsd($s['budget']['budget_micro'], 2) }} za {{ $s['budget']['month'] }}. Rozpočet je iba alarm – zaplatené použitia sa neskracujú.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Domácnosti</flux:text>
            <flux:heading size="xl" class="font-display">{{ number_format($s['households'], 0, ',', ' ') }}</flux:heading>
            <flux:text class="text-xs">{{ $s['users'] }} účtov · {{ $s['recipes'] }} receptov · {{ $s['admins'] }} admin</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">AI náklady dnes</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['today']['cost_micro']) }}</flux:heading>
            <flux:text class="text-xs">{{ $s['today']['jobs'] }} úloh · {{ $s['today']['failed'] }} chýb</flux:text>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">AI náklady {{ $s['budget']['month'] }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['month']['cost_micro']) }}</flux:heading>
            @if ($s['budget']['budget_micro'] !== null)
                @php($pct = min(100, (int) round(($s['budget']['ratio'] ?? 0) * 100)))
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full {{ $s['budget']['exceeded'] ? 'bg-red-500' : 'bg-accent' }}" style="width: {{ $pct }}%"></div>
                </div>
                <flux:text class="text-xs">{{ $pct }} % z rozpočtu {{ Money::microUsd($s['budget']['budget_micro'], 2) }}</flux:text>
            @else
                <flux:text class="text-xs">Rozpočet nie je nastavený</flux:text>
            @endif
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Posledných 30 dní</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($s['days30']['cost_micro']) }}</flux:heading>
            <flux:text class="text-xs">{{ $s['days30']['succeeded'] }} doručených · {{ $s['days30']['failed'] }} chýb @if ($s['days30']['unpriced'] > 0) · {{ $s['days30']['unpriced'] }} bez ceny @endif</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">AI prevádzka</flux:heading>
            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-zinc-500">Stav</dt>
                <dd>{!! $this->settings->enabled() ? '<span class="text-green-700 dark:text-green-400">zapnuté</span>' : '<span class="text-red-600">vypnuté</span>' !!}</dd>
                <dt class="text-zinc-500">Textový model</dt>
                <dd>{{ $this->settings->textModel() ?? 'predvolený poskytovateľa' }} · effort {{ $this->settings->textReasoningEffort() }}</dd>
                <dt class="text-zinc-500">Obrázkový model</dt>
                <dd>{{ $this->settings->imageModel() ?? 'predvolený poskytovateľa' }} · predvolený profil {{ $this->settings->defaultImageProfile()->label() }} ({{ $this->settings->imageQuality() }} · {{ $this->settings->imagePixelSize() }})</dd>
                <dt class="text-zinc-500">Bežiace úlohy</dt>
                <dd>{{ $s['ai_active'] }}</dd>
                <dt class="text-zinc-500">Chyby za 24 h</dt>
                <dd class="{{ $s['ai_failed_24h'] > 0 ? 'text-red-600' : '' }}">{{ $s['ai_failed_24h'] }}</dd>
            </dl>
            <div class="flex gap-2">
                <flux:button size="sm" :href="route('admin.ai')" wire:navigate icon="cpu-chip">Použitie a náklady</flux:button>
                <flux:button size="sm" variant="ghost" :href="route('admin.ai.settings')" wire:navigate icon="adjustments-horizontal">Nastavenia</flux:button>
            </div>
        </flux:card>

        <flux:card class="space-y-3">
            <flux:heading size="lg" class="font-display">Fronta</flux:heading>
            <dl class="grid grid-cols-2 gap-y-2 text-sm">
                <dt class="text-zinc-500">Čakajúce úlohy</dt>
                <dd>{{ $s['queue_pending'] }}</dd>
                <dt class="text-zinc-500">Zlyhané úlohy (failed_jobs)</dt>
                <dd class="{{ $s['queue_failed'] > 0 ? 'text-red-600' : '' }}">{{ $s['queue_failed'] }}</dd>
            </dl>
            <flux:text class="text-xs">Zlyhané úlohy fronty sa riešia cez <code>php artisan queue:retry</code>; AI úlohy majú jediný pokus, opakovanie je vedomá akcia používateľa.</flux:text>
        </flux:card>
    </div>
</div>
