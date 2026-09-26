<?php

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReport;
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

    #[Computed]
    public function settings(): AiSettings
    {
        return app(AiSettings::class);
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Prehľad" subtitle="Stav aplikácie, AI náklady a fronta. Finančné ukazovatele (MRR, inkaso, refundácie) pribudnú s etapou Cashier." />

    @php($s = $this->stats)

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
                <dd>{{ $this->settings->imageModel() ?? 'predvolený poskytovateľa' }} · {{ $this->settings->imageQuality() }} · {{ $this->settings->imagePixelSize() }}</dd>
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
