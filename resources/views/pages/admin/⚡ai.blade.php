<?php

use App\Services\Ai\AiUsageReport;
use App\Services\Ai\ImageProfile;
use App\Services\Ai\ImageProfileComparison;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('AI použitie a náklady')] class extends Component {
    #[Url]
    public int $days = 30;

    #[Url]
    public string $kind = '';

    #[Url]
    public string $status = '';

    public function updated(string $property): void
    {
        if ($property === 'days' && ! in_array($this->days, [7, 30, 90], true)) {
            $this->days = 30;
        }
        if ($property === 'kind' && ! in_array($this->kind, ['', 'text', 'image', 'meal_analysis'], true)) {
            $this->kind = '';
        }
        if ($property === 'status' && ! in_array($this->status, ['', 'succeeded', 'failed', 'queued', 'running', 'reconciling'], true)) {
            $this->status = '';
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    #[Computed]
    public function range(): array
    {
        $now = CarbonImmutable::now($this->report()->timezone());

        return [$now->subDays(max(1, $this->days) - 1)->startOfDay(), $now->endOfDay()];
    }

    /** @return array<string, int> */
    #[Computed]
    public function summary(): array
    {
        return $this->report()->summary(...$this->range);
    }

    #[Computed]
    public function byModel(): Collection
    {
        return $this->report()->byModel(...$this->range);
    }

    #[Computed]
    public function byHousehold(): Collection
    {
        return $this->report()->byHousehold(...$this->range);
    }

    #[Computed]
    public function byImageProfile(): Collection
    {
        return $this->report()->byImageProfile(...$this->range);
    }

    /** Stored low/medium comparison runs (v2.1 stage 8), newest first. */
    #[Computed]
    public function comparisons(): Collection
    {
        return app(ImageProfileComparison::class)->runs();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function daily(): array
    {
        return array_reverse($this->report()->daily(...$this->range));
    }

    #[Computed]
    public function jobs(): Collection
    {
        return $this->report()->recentJobs(...$this->range, kind: $this->kind ?: null, status: $this->status ?: null);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function budget(): array
    {
        return $this->report()->budget();
    }

    #[Computed]
    public function timezone(): string
    {
        return $this->report()->timezone();
    }

    protected function report(): AiUsageReport
    {
        return app(AiUsageReport::class);
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('AI použitie a náklady')" :subtitle="__('Meranie usage od poskytovateľa je autoritatívne; cena je odhad podľa verzovaného cenníka. Obsah receptov sa tu nezobrazuje.')">
        <flux:button size="sm" variant="ghost" :href="route('admin.ai.settings')" wire:navigate icon="adjustments-horizontal">{{ __('Nastavenia') }}</flux:button>
    </x-page-header>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="days" :label="__('Obdobie')" size="sm" class="w-40">
            <flux:select.option value="7">{{ __('Posledných 7 dní') }}</flux:select.option>
            <flux:select.option value="30">{{ __('Posledných 30 dní') }}</flux:select.option>
            <flux:select.option value="90">{{ __('Posledných 90 dní') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="kind" :label="__('Druh')" size="sm" class="w-40">
            <flux:select.option value="">{{ __('Všetky') }}</flux:select.option>
            <flux:select.option value="text">{{ __('Text') }}</flux:select.option>
            <flux:select.option value="image">{{ __('Obrázok') }}</flux:select.option>
            <flux:select.option value="meal_analysis">{{ __('Analýza jedla') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="status" :label="__('Stav')" size="sm" class="w-40">
            <flux:select.option value="">{{ __('Všetky') }}</flux:select.option>
            <flux:select.option value="succeeded">{{ __('Doručené') }}</flux:select.option>
            <flux:select.option value="failed">{{ __('Chyba') }}</flux:select.option>
            <flux:select.option value="queued">{{ __('Vo fronte') }}</flux:select.option>
            <flux:select.option value="running">{{ __('Beží') }}</flux:select.option>
            <flux:select.option value="reconciling">{{ __('Overuje sa') }}</flux:select.option>
        </flux:select>
    </div>

    @php($sum = $this->summary)
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Odhad nákladov') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($sum['cost_micro']) }}</flux:heading>
            @if ($sum['unpriced'] > 0)
                <flux:text class="text-xs text-amber-700 dark:text-amber-400">{{ __(':count doručených úloh bez ceny – chýba sadzba v cenníku.', ['count' => $sum['unpriced']]) }}</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Úlohy') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ $sum['jobs'] }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count doručených', ['count' => $sum['succeeded']]) }} · {{ __(':count chýb', ['count' => $sum['failed']]) }} · {{ __(':count aktívnych', ['count' => $sum['active']]) }}</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Tokeny') }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ number_format($sum['input_tokens'] + $sum['output_tokens'], 0, ',', ' ') }}</flux:heading>
            <flux:text class="text-xs">{{ __(':count vstup', ['count' => number_format($sum['input_tokens'], 0, ',', ' ')]) }} · {{ __(':count výstup (z toho reasoning :reasoning)', ['count' => number_format($sum['output_tokens'], 0, ',', ' '), 'reasoning' => number_format($sum['reasoning_tokens'], 0, ',', ' ')]) }}</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Mesiac :month', ['month' => $this->budget['month']]) }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($this->budget['spent_micro']) }}</flux:heading>
            <flux:text class="text-xs">
                @if ($this->budget['budget_micro'] !== null)
                    {{ __('rozpočet :budget (:percent %)', ['budget' => Money::microUsd($this->budget['budget_micro'], 2), 'percent' => (int) round(($this->budget['ratio'] ?? 0) * 100)]) }}
                @else
                    {{ __('bez rozpočtu') }}
                @endif
            </flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <flux:card class="space-y-3 overflow-x-auto">
            <flux:heading size="lg" class="font-display">{{ __('Podľa modelu') }}</flux:heading>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">{{ __('Druh') }}</th><th class="py-1 pe-2">{{ __('Model') }}</th><th class="py-1 pe-2 text-right">{{ __('Úlohy') }}</th><th class="py-1 pe-2 text-right">{{ __('Doručené') }}</th><th class="py-1 pe-2 text-right">{{ __('Tokeny') }}</th><th class="py-1 pe-2 text-right">{{ __('Náklad') }}</th><th class="py-1 text-right">{{ __('Ø / doručenie') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->byModel as $row)
                        <tr>
                            <td class="py-1.5 pe-2">{{ \App\Enums\AiJobKind::tryFrom($row->kind)?->label() ?? $row->kind }}</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $row->model ?? __('(predvolený)') }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->succeeded }} @if ($row->failed) <span class="text-red-600">/ {{ $row->failed }}</span> @endif</td>
                            <td class="py-1.5 pe-2 text-right">{{ number_format($row->input_tokens + $row->output_tokens, 0, ',', ' ') }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ Money::microUsd($row->cost_micro) }}</td>
                            <td class="py-1.5 text-right">{{ Money::microUsd($row->avg_cost_micro) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 text-zinc-500">{{ __('V období nie sú žiadne AI úlohy.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>

        <flux:card class="space-y-3 overflow-x-auto">
            <flux:heading size="lg" class="font-display">{{ __('Podľa domácnosti') }}</flux:heading>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">{{ __('Domácnosť') }}</th><th class="py-1 pe-2 text-right">{{ __('Text') }}</th><th class="py-1 pe-2 text-right">{{ __('Obrázky') }}</th><th class="py-1 pe-2 text-right">{{ __('Jedlá') }}</th><th class="py-1 text-right">{{ __('Náklad') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->byHousehold as $row)
                        <tr>
                            <td class="py-1.5 pe-2">#{{ $row->household_id }} {{ $row->name }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->text_jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->image_jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->meal_analysis_jobs }}</td>
                            <td class="py-1.5 text-right">{{ Money::microUsd($row->cost_micro) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-zinc-500">{{ __('Žiadne dáta.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <flux:card class="space-y-3 overflow-x-auto" data-test="by-image-profile">
            <flux:heading size="lg" class="font-display">{{ __('Obrázky podľa profilu') }}</flux:heading>
            <flux:text class="text-xs">{{ __('Profil je snímkovaný na úlohe (kód, kvalita, rozmer); staršie úlohy bez kódu sú Standard.') }} {{ __('Predvolený profil pre nové použitia: :profile.', ['profile' => app(\App\Services\Ai\AiSettings::class)->defaultImageProfile()->label()]) }}</flux:text>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">{{ __('Profil') }}</th><th class="py-1 pe-2">{{ __('Model') }}</th><th class="py-1 pe-2 text-right">{{ __('Úlohy') }}</th><th class="py-1 pe-2 text-right">{{ __('Doručené') }}</th><th class="py-1 pe-2 text-right">{{ __('Náklad') }}</th><th class="py-1 text-right">{{ __('Ø / doručenie') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->byImageProfile as $row)
                        <tr>
                            <td class="py-1.5 pe-2">{{ $row->label }} <span class="font-mono text-xs text-zinc-500">{{ $row->code }}</span></td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $row->model ?? __('(predvolený)') }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->succeeded }} @if ($row->failed) <span class="text-red-600">/ {{ $row->failed }}</span> @endif</td>
                            <td class="py-1.5 pe-2 text-right">{{ Money::microUsd($row->cost_micro) }}</td>
                            <td class="py-1.5 text-right">{{ Money::microUsd($row->avg_cost_micro) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-zinc-500">{{ __('V období nie sú žiadne obrázkové úlohy.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>

        <flux:card class="space-y-3" data-test="comparisons">
            <flux:heading size="lg" class="font-display">{{ __('Porovnania low / medium') }}</flux:heading>
            <flux:text class="text-xs">{!! __('Rozhodovací experiment z dodatku v2.1: 10 jedál × (2 Economy + 2 Standard) na reálnom kľúči (:command).', ['command' => '<code>php artisan app:ai-compare-images &lt;domácnosť&gt; --yes</code>']) !!} {{ __('Hodnotí administrátor; výsledok je podklad pre etapu 13, ponuka sa tu nemení.') }}</flux:text>
            <ul class="divide-y divide-zinc-200/70 text-sm dark:divide-zinc-800">
                @forelse ($this->comparisons as $run)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <a href="{{ route('admin.ai.comparison', $run['run']) }}" class="font-medium underline" wire:navigate>{{ $run['run'] }}</a>
                            <span class="text-xs text-zinc-500">· {{ \Carbon\CarbonImmutable::parse($run['at'])->timezone($this->timezone)->format('j. n. Y H:i') }} · {{ __('domácnosť #:id', ['id' => $run['household_id']]) }} · {{ __(':count úloh', ['count' => count($run['job_ids'] ?? [])]) }} · {{ __(':count hodnotení', ['count' => count($run['evaluations'] ?? [])]) }}</span>
                        </div>
                        @if ($run['decision'] ?? null)
                            <flux:badge size="sm" color="green">{{ __('rozhodnuté: :profile', ['profile' => ImageProfile::tryFrom($run['decision']['profile'] ?? '')?->label() ?? $run['decision']['profile']]) }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('bez rozhodnutia') }}</flux:badge>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-zinc-500">{{ __('Zatiaľ žiadny beh porovnania.') }}</li>
                @endforelse
            </ul>
        </flux:card>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <flux:heading size="lg" class="font-display">{{ __('Po dňoch (:timezone)', ['timezone' => $this->timezone]) }}</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">{{ __('Deň') }}</th><th class="py-1 pe-2 text-right">{{ __('Úlohy') }}</th><th class="py-1 pe-2 text-right">{{ __('Text') }}</th><th class="py-1 pe-2 text-right">{{ __('Obrázky') }}</th><th class="py-1 pe-2 text-right">{{ __('Jedlá') }}</th><th class="py-1 pe-2 text-right">{{ __('Chyby') }}</th><th class="py-1 text-right">{{ __('Náklad') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->daily as $day)
                    <tr class="{{ $day['jobs'] === 0 ? 'text-zinc-400' : '' }}">
                        <td class="py-1 pe-2">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('D d.m.Y') }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['jobs'] }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['text_jobs'] }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['image_jobs'] }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['meal_analysis_jobs'] }}</td>
                        <td class="py-1 pe-2 text-right {{ $day['failed'] > 0 ? 'text-red-600' : '' }}">{{ $day['failed'] }}</td>
                        <td class="py-1 text-right">{{ Money::microUsd($day['cost_micro']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto">
        <flux:heading size="lg" class="font-display">{{ __('Posledné úlohy') }}</flux:heading>
        <flux:text class="text-xs">{{ __('Bez promptu a výsledku – podporný prístup k obsahu receptu iba pri konkrétnej potrebe a s auditom (neskoršia etapa).') }} {{ __('Analýzy jedla: iba metadáta úlohy (stav, tokeny, cena, redigovaná chyba) – nikdy fotka ani zložky; doplnenia sú úlohy s nadradenou úlohou.') }}</flux:text>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="py-1 pe-2">{{ __('Čas') }}</th><th class="py-1 pe-2">{{ __('Domácnosť') }}</th><th class="py-1 pe-2">{{ __('Druh') }}</th><th class="py-1 pe-2">{{ __('Model · profil') }}</th>
                    <th class="py-1 pe-2 text-right">{{ __('Vstup') }}</th><th class="py-1 pe-2 text-right">{{ __('Výstup') }}</th><th class="py-1 pe-2 text-right">{{ __('Náklad') }}</th><th class="py-1 pe-2 text-right">{{ __('Trvanie') }}</th><th class="py-1 pe-2">{{ __('Stav') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->jobs as $job)
                    <tr>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $job->created_at?->setTimezone($this->timezone)->format('d.m. H:i') }}</td>
                        <td class="py-1.5 pe-2">#{{ $job->household_id }} {{ $job->household?->name }}</td>
                        <td class="py-1.5 pe-2">{{ $job->kind->label() }}@if ($job->parent_ai_job_id) <span class="text-xs text-zinc-500">{{ __('(doplnenie #:id)', ['id' => $job->parent_ai_job_id]) }}</span>@endif</td>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $job->model ?? __('(predvolený)') }}<br><span class="text-zinc-500">{{ $job->profileLabel() }}</span></td>
                        <td class="py-1.5 pe-2 text-right">{{ $job->input_tokens !== null ? number_format($job->input_tokens, 0, ',', ' ') : '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">
                            {{ $job->output_tokens !== null ? number_format($job->output_tokens, 0, ',', ' ') : '–' }}
                            @if ($job->reasoning_tokens) <span class="text-xs text-zinc-500">{{ __('(r :count)', ['count' => number_format($job->reasoning_tokens, 0, ',', ' ')]) }}</span> @endif
                        </td>
                        <td class="py-1.5 pe-2 text-right">{{ Money::microUsd($job->estimated_cost_micro_usd) }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $job->duration_ms !== null ? __(':seconds s', ['seconds' => number_format($job->duration_ms / 1000, 1, ',', ' ')]) : '–' }}</td>
                        <td class="py-1.5 pe-2">
                            @php($color = match ($job->status->value) { 'succeeded' => 'green', 'failed' => 'red', 'running' => 'blue', 'reconciling' => 'amber', default => 'zinc' })
                            <flux:badge size="sm" :color="$color">{{ $job->status->value }}</flux:badge>
                            @if ($job->error)
                                <div class="mt-1 max-w-xs truncate text-xs text-red-600" title="{{ $job->error }}">{{ \Illuminate\Support\Str::limit($job->error, 80) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-3 text-zinc-500">{{ __('Žiadne úlohy pre zvolený filter.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
