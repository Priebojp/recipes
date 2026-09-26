<?php

use App\Services\Ai\AiUsageReport;
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
        if ($property === 'kind' && ! in_array($this->kind, ['', 'text', 'image'], true)) {
            $this->kind = '';
        }
        if ($property === 'status' && ! in_array($this->status, ['', 'succeeded', 'failed', 'queued', 'running'], true)) {
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
    <x-page-header title="AI použitie a náklady" subtitle="Meranie usage od poskytovateľa je autoritatívne; cena je odhad podľa verzovaného cenníka. Obsah receptov sa tu nezobrazuje.">
        <flux:button size="sm" variant="ghost" :href="route('admin.ai.settings')" wire:navigate icon="adjustments-horizontal">Nastavenia</flux:button>
    </x-page-header>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="days" label="Obdobie" size="sm" class="w-40">
            <flux:select.option value="7">Posledných 7 dní</flux:select.option>
            <flux:select.option value="30">Posledných 30 dní</flux:select.option>
            <flux:select.option value="90">Posledných 90 dní</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="kind" label="Druh" size="sm" class="w-40">
            <flux:select.option value="">Všetky</flux:select.option>
            <flux:select.option value="text">Text</flux:select.option>
            <flux:select.option value="image">Obrázok</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="status" label="Stav" size="sm" class="w-40">
            <flux:select.option value="">Všetky</flux:select.option>
            <flux:select.option value="succeeded">Doručené</flux:select.option>
            <flux:select.option value="failed">Chyba</flux:select.option>
            <flux:select.option value="queued">Vo fronte</flux:select.option>
            <flux:select.option value="running">Beží</flux:select.option>
        </flux:select>
    </div>

    @php($sum = $this->summary)
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Odhad nákladov</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($sum['cost_micro']) }}</flux:heading>
            @if ($sum['unpriced'] > 0)
                <flux:text class="text-xs text-amber-700 dark:text-amber-400">{{ $sum['unpriced'] }} doručených úloh bez ceny – chýba sadzba v cenníku.</flux:text>
            @endif
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Úlohy</flux:text>
            <flux:heading size="xl" class="font-display">{{ $sum['jobs'] }}</flux:heading>
            <flux:text class="text-xs">{{ $sum['succeeded'] }} doručených · {{ $sum['failed'] }} chýb · {{ $sum['active'] }} aktívnych</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Tokeny</flux:text>
            <flux:heading size="xl" class="font-display">{{ number_format($sum['input_tokens'] + $sum['output_tokens'], 0, ',', ' ') }}</flux:heading>
            <flux:text class="text-xs">{{ number_format($sum['input_tokens'], 0, ',', ' ') }} vstup · {{ number_format($sum['output_tokens'], 0, ',', ' ') }} výstup (z toho reasoning {{ number_format($sum['reasoning_tokens'], 0, ',', ' ') }})</flux:text>
        </flux:card>
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">Mesiac {{ $this->budget['month'] }}</flux:text>
            <flux:heading size="xl" class="font-display">{{ Money::microUsd($this->budget['spent_micro']) }}</flux:heading>
            <flux:text class="text-xs">
                @if ($this->budget['budget_micro'] !== null)
                    rozpočet {{ Money::microUsd($this->budget['budget_micro'], 2) }} ({{ (int) round(($this->budget['ratio'] ?? 0) * 100) }} %)
                @else
                    bez rozpočtu
                @endif
            </flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <flux:card class="space-y-3 overflow-x-auto">
            <flux:heading size="lg" class="font-display">Podľa modelu</flux:heading>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2">Model</th><th class="py-1 pe-2 text-right">Úlohy</th><th class="py-1 pe-2 text-right">Doručené</th><th class="py-1 pe-2 text-right">Tokeny</th><th class="py-1 pe-2 text-right">Náklad</th><th class="py-1 text-right">Ø / doručenie</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->byModel as $row)
                        <tr>
                            <td class="py-1.5 pe-2">{{ $row->kind === 'image' ? 'Obrázok' : 'Text' }}</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ $row->model ?? '(predvolený)' }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->succeeded }} @if ($row->failed) <span class="text-red-600">/ {{ $row->failed }}</span> @endif</td>
                            <td class="py-1.5 pe-2 text-right">{{ number_format($row->input_tokens + $row->output_tokens, 0, ',', ' ') }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ Money::microUsd($row->cost_micro) }}</td>
                            <td class="py-1.5 text-right">{{ Money::microUsd($row->avg_cost_micro) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 text-zinc-500">V období nie sú žiadne AI úlohy.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>

        <flux:card class="space-y-3 overflow-x-auto">
            <flux:heading size="lg" class="font-display">Podľa domácnosti</flux:heading>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500">
                    <tr><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2 text-right">Text</th><th class="py-1 pe-2 text-right">Obrázky</th><th class="py-1 text-right">Náklad</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->byHousehold as $row)
                        <tr>
                            <td class="py-1.5 pe-2">#{{ $row->household_id }} {{ $row->name }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->text_jobs }}</td>
                            <td class="py-1.5 pe-2 text-right">{{ $row->image_jobs }}</td>
                            <td class="py-1.5 text-right">{{ Money::microUsd($row->cost_micro) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-zinc-500">Žiadne dáta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <flux:heading size="lg" class="font-display">Po dňoch ({{ $this->timezone }})</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">Deň</th><th class="py-1 pe-2 text-right">Úlohy</th><th class="py-1 pe-2 text-right">Text</th><th class="py-1 pe-2 text-right">Obrázky</th><th class="py-1 pe-2 text-right">Chyby</th><th class="py-1 text-right">Náklad</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->daily as $day)
                    <tr class="{{ $day['jobs'] === 0 ? 'text-zinc-400' : '' }}">
                        <td class="py-1 pe-2">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('D d.m.Y') }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['jobs'] }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['text_jobs'] }}</td>
                        <td class="py-1 pe-2 text-right">{{ $day['image_jobs'] }}</td>
                        <td class="py-1 pe-2 text-right {{ $day['failed'] > 0 ? 'text-red-600' : '' }}">{{ $day['failed'] }}</td>
                        <td class="py-1 text-right">{{ Money::microUsd($day['cost_micro']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto">
        <flux:heading size="lg" class="font-display">Posledné úlohy</flux:heading>
        <flux:text class="text-xs">Bez promptu a výsledku – podporný prístup k obsahu receptu iba pri konkrétnej potrebe a s auditom (neskoršia etapa).</flux:text>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="py-1 pe-2">Čas</th><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2">Model · profil</th>
                    <th class="py-1 pe-2 text-right">Vstup</th><th class="py-1 pe-2 text-right">Výstup</th><th class="py-1 pe-2 text-right">Náklad</th><th class="py-1 pe-2 text-right">Trvanie</th><th class="py-1 pe-2">Stav</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->jobs as $job)
                    <tr>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $job->created_at?->setTimezone($this->timezone)->format('d.m. H:i') }}</td>
                        <td class="py-1.5 pe-2">#{{ $job->household_id }} {{ $job->household?->name }}</td>
                        <td class="py-1.5 pe-2">{{ $job->kind->value === 'image' ? 'Obrázok' : 'Text' }}</td>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $job->model ?? '(predvolený)' }}<br><span class="text-zinc-500">{{ $job->profileLabel() }}</span></td>
                        <td class="py-1.5 pe-2 text-right">{{ $job->input_tokens !== null ? number_format($job->input_tokens, 0, ',', ' ') : '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">
                            {{ $job->output_tokens !== null ? number_format($job->output_tokens, 0, ',', ' ') : '–' }}
                            @if ($job->reasoning_tokens) <span class="text-xs text-zinc-500">(r {{ number_format($job->reasoning_tokens, 0, ',', ' ') }})</span> @endif
                        </td>
                        <td class="py-1.5 pe-2 text-right">{{ Money::microUsd($job->estimated_cost_micro_usd) }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $job->duration_ms !== null ? number_format($job->duration_ms / 1000, 1, ',', ' ').' s' : '–' }}</td>
                        <td class="py-1.5 pe-2">
                            @php($color = match ($job->status->value) { 'succeeded' => 'green', 'failed' => 'red', 'running' => 'blue', default => 'zinc' })
                            <flux:badge size="sm" :color="$color">{{ $job->status->value }}</flux:badge>
                            @if ($job->error)
                                <div class="mt-1 max-w-xs truncate text-xs text-red-600" title="{{ $job->error }}">{{ \Illuminate\Support\Str::limit($job->error, 80) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-3 text-zinc-500">Žiadne úlohy pre zvolený filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
