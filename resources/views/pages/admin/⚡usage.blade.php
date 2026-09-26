<?php

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Enums\UsageReservationState;
use App\Models\UsageGrant;
use App\Models\UsageLedgerEntry;
use App\Models\UsageReservation;
use App\Services\Admin\AdminAuditor;
use App\Services\Usage\UsageLedger;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The usage ledger: grants with their counters, open reservations and the append-only movements of one grant.
 * The only write here rebuilds a grant's cached counters from the ledger (never a balance edit).
 */
new #[Layout('layouts::admin')] #[Title('AI použitia')] class extends Component {
    use WithPagination;

    #[Url]
    public string $source = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $household = '';

    #[Url]
    public bool $open = false;

    public ?int $expanded = null;

    public function updated(string $property): void
    {
        if ($property === 'source' && $this->source !== '' && UsageGrantSource::tryFrom($this->source) === null) {
            $this->source = '';
        }
        if ($property === 'kind' && $this->kind !== '' && UsageKind::tryFrom($this->kind) === null) {
            $this->kind = '';
        }
        $this->resetPage();
    }

    #[Computed]
    public function grants(): LengthAwarePaginator
    {
        return UsageGrant::query()
            ->with('household:id,name')
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source))
            ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
            ->when(ctype_digit(trim($this->household)), fn (Builder $q) => $q->where('household_id', (int) trim($this->household)))
            ->latest('id')
            ->paginate(25);
    }

    /** Reservations held longer than a day (or all open ones when the filter is on). */
    #[Computed]
    public function openReservations(): Collection
    {
        return UsageReservation::query()
            ->where('state', UsageReservationState::Reserved)
            ->when(! $this->open, fn (Builder $q) => $q->where('reserved_at', '<', now()->subDay()))
            ->with(['aiJob:id,status,kind,created_at', 'grant:id,kind,source'])
            ->orderBy('reserved_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function entries(): Collection
    {
        if ($this->expanded === null) {
            return collect();
        }

        return UsageLedgerEntry::query()->where('usage_grant_id', $this->expanded)->with('actor:id,email')->orderBy('id')->get();
    }

    /** @return array<string, array{granted: int, consumed: int, reserved: int, available: int}> */
    #[Computed]
    public function totals(): array
    {
        $out = [];
        foreach (UsageKind::cases() as $kind) {
            $row = UsageGrant::query()->where('kind', $kind)->validAt(now())
                ->selectRaw('coalesce(sum(quantity - revoked_quantity), 0) as granted, coalesce(sum(consumed_quantity), 0) as consumed, coalesce(sum(reserved_quantity), 0) as reserved, coalesce(sum(quantity - reserved_quantity - consumed_quantity - revoked_quantity), 0) as available')
                ->toBase()->first();
            $out[$kind->value] = ['granted' => (int) $row->granted, 'consumed' => (int) $row->consumed, 'reserved' => (int) $row->reserved, 'available' => (int) $row->available];
        }

        return $out;
    }

    public function toggle(int $grantId): void
    {
        $this->expanded = $this->expanded === $grantId ? null : $grantId;
        unset($this->entries);
    }

    public function reconcile(int $grantId, UsageLedger $ledger, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $grant = UsageGrant::query()->findOrFail($grantId);
        $before = $grant->only(['reserved_quantity', 'consumed_quantity']);

        $consistent = $ledger->reconcile($grant);
        $after = $grant->fresh()->only(['reserved_quantity', 'consumed_quantity']);
        if (! $consistent) {
            $audit->record('usage.grant.reconciled', $grant, $before, $after, 'Prepočet počítadiel z ledgeru');
        }

        unset($this->grants, $this->totals);
        Flux::toast(text: $consistent ? "Grant #{$grantId}: počítadlá sedia s ledgerom." : "Grant #{$grantId}: počítadlá opravené z ledgeru (zapísané v audite).");
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="AI použitia (ledger)" subtitle="Granty, rezervácie, spotreba a kompenzácie s dôvodmi. Zostatky sa neupravujú priamo – iba novými grantmi, refundáciou alebo prepočtom z ledgeru." />

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (UsageKind::cases() as $kind)
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide">{{ $kind->label() }} – platné granty</flux:text>
                <flux:heading size="xl" class="font-display">{{ $this->totals[$kind->value]['available'] }} <span class="text-base font-normal text-zinc-500">voľných z {{ $this->totals[$kind->value]['granted'] }}</span></flux:heading>
                <flux:text class="text-xs">{{ $this->totals[$kind->value]['consumed'] }} spotrebovaných · {{ $this->totals[$kind->value]['reserved'] }} rezervovaných</flux:text>
            </flux:card>
        @endforeach
    </div>

    @if ($this->openReservations->isNotEmpty())
        <flux:card class="space-y-2 overflow-x-auto" data-test="open-reservations">
            <div class="flex items-center justify-between">
                <flux:heading size="lg" class="font-display">Otvorené rezervácie {{ $open ? '' : '(dlhšie ako deň)' }}</flux:heading>
                <flux:switch wire:model.live="open" label="všetky otvorené" />
            </div>
            <flux:text class="text-xs">Rezervácia zostáva pri úlohe v stave „overuje sa“. Rozhodnutie robí <code>php artisan app:ai-reconcile</code>; uvoľnenie zapíše ledger a audit.</flux:text>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @foreach ($this->openReservations as $r)
                        <tr wire:key="res-{{ $r->id }}">
                            <td class="py-1.5 pe-2">rezervácia {{ $r->id }}</td>
                            <td class="py-1.5 pe-2">dom. <a href="{{ route('admin.households.show', $r->household_id) }}" class="underline" wire:navigate>#{{ $r->household_id }}</a></td>
                            <td class="py-1.5 pe-2">grant {{ $r->usage_grant_id }} ({{ $r->grant?->kind->label() }})</td>
                            <td class="py-1.5 pe-2">AI úloha {{ $r->ai_job_id ?? '–' }} · {{ $r->aiJob?->status->label() ?? 'bez úlohy' }}</td>
                            <td class="py-1.5 whitespace-nowrap">{{ $r->reserved_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </flux:card>
    @elseif ($open)
        <flux:callout icon="check-circle" variant="success" class="text-sm"><flux:callout.text>Žiadne otvorené rezervácie.</flux:callout.text></flux:callout>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="household" placeholder="ID domácnosti" class="w-40" />
        <flux:select wire:model.live="source" class="w-48">
            <flux:select.option value="">všetky zdroje</flux:select.option>
            @foreach (UsageGrantSource::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="kind" class="w-48">
            <flux:select.option value="">oba druhy</flux:select.option>
            @foreach (UsageKind::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2">Zdroj</th><th class="py-1 pe-2 text-right">Množstvo</th><th class="py-1 pe-2 text-right">Rezerv.</th><th class="py-1 pe-2 text-right">Spotreb.</th><th class="py-1 pe-2 text-right">Odobr.</th><th class="py-1 pe-2 text-right">Voľné</th><th class="py-1 pe-2">Platnosť</th><th class="py-1 pe-2">Poznámka / dôvod</th><th class="py-1"></th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->grants as $g)
                    <tr wire:key="grant-{{ $g->id }}" class="{{ $g->isValidAt(now()) ? '' : 'text-zinc-400' }}">
                        <td class="py-1.5 pe-2">{{ $g->id }}</td>
                        <td class="py-1.5 pe-2"><a href="{{ route('admin.households.show', $g->household_id) }}" class="underline" wire:navigate>#{{ $g->household_id }}</a></td>
                        <td class="py-1.5 pe-2">{{ $g->kind->label() }}</td>
                        <td class="py-1.5 pe-2">{{ $g->source->label() }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->reserved_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->consumed_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->revoked_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right font-medium tabular-nums">{{ $g->available() }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap text-xs">{{ $g->valid_from->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }} – {{ $g->expires_at?->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') ?? '∞' }}</td>
                        <td class="py-1.5 pe-2 max-w-xs truncate text-xs" title="{{ $g->source_key }}">{{ $g->note }}</td>
                        <td class="py-1.5 whitespace-nowrap text-right">
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $g->id }})">{{ $expanded === $g->id ? 'skryť' : 'ledger' }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="calculator" wire:click="reconcile({{ $g->id }})" title="Prepočítať počítadlá z ledgeru" />
                        </td>
                    </tr>
                    @if ($expanded === $g->id)
                        <tr wire:key="ledger-{{ $g->id }}">
                            <td colspan="12" class="bg-zinc-50 p-2 dark:bg-zinc-800/50">
                                <table class="w-full text-xs">
                                    <thead class="text-left uppercase text-zinc-500"><tr><th class="py-0.5 pe-2">Čas</th><th class="py-0.5 pe-2">Dôvod</th><th class="py-0.5 pe-2 text-right">Pohyb</th><th class="py-0.5 pe-2">Rezervácia</th><th class="py-0.5 pe-2">Kto</th><th class="py-0.5 pe-2">Kľúč</th><th class="py-0.5">Poznámka</th></tr></thead>
                                    <tbody>
                                        @foreach ($this->entries as $e)
                                            <tr wire:key="entry-{{ $e->id }}">
                                                <td class="py-0.5 pe-2 whitespace-nowrap">{{ $e->created_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i:s') }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->reason->value }}</td>
                                                <td class="py-0.5 pe-2 text-right tabular-nums {{ $e->movement < 0 ? 'text-red-600' : ($e->movement > 0 ? 'text-green-700' : '') }}">{{ $e->movement > 0 ? '+' : '' }}{{ $e->movement }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->usage_reservation_id ?? '–' }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->actor?->email ?? 'systém' }}</td>
                                                <td class="py-0.5 pe-2 font-mono">{{ $e->source_key }}</td>
                                                <td class="py-0.5">{{ $e->note }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="12" class="py-3 text-zinc-500">Žiadne granty.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->grants->links() }}
    </flux:card>
</div>
