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
        Flux::toast(text: $consistent ? __('Grant #:id: počítadlá sedia s ledgerom.', ['id' => $grantId]) : __('Grant #:id: počítadlá opravené z ledgeru (zapísané v audite).', ['id' => $grantId]));
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('AI použitia (ledger)')" :subtitle="__('Granty, rezervácie, spotreba a kompenzácie s dôvodmi. Zostatky sa neupravujú priamo – iba novými grantmi, refundáciou alebo prepočtom z ledgeru.')" />

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (UsageKind::cases() as $kind)
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide">{{ __(':kind – platné granty', ['kind' => $kind->label()]) }}</flux:text>
                <flux:heading size="xl" class="font-display">{{ $this->totals[$kind->value]['available'] }} <span class="text-base font-normal text-zinc-500">{{ __('voľných z :granted', ['granted' => $this->totals[$kind->value]['granted']]) }}</span></flux:heading>
                <flux:text class="text-xs">{{ __(':consumed spotrebovaných · :reserved rezervovaných', ['consumed' => $this->totals[$kind->value]['consumed'], 'reserved' => $this->totals[$kind->value]['reserved']]) }}</flux:text>
            </flux:card>
        @endforeach
    </div>

    @if ($this->openReservations->isNotEmpty())
        <flux:card class="space-y-2 overflow-x-auto" data-test="open-reservations">
            <div class="flex items-center justify-between">
                <flux:heading size="lg" class="font-display">{{ $open ? __('Otvorené rezervácie') : __('Otvorené rezervácie (dlhšie ako deň)') }}</flux:heading>
                <flux:switch wire:model.live="open" :label="__('všetky otvorené')" />
            </div>
            <flux:text class="text-xs">{!! __('Rezervácia zostáva pri úlohe v stave „overuje sa“. Rozhodnutie robí :command; uvoľnenie zapíše ledger a audit.', ['command' => '<code>php artisan app:ai-reconcile</code>']) !!}</flux:text>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @foreach ($this->openReservations as $r)
                        <tr wire:key="res-{{ $r->id }}">
                            <td class="py-1.5 pe-2">{{ __('rezervácia :id', ['id' => $r->id]) }}</td>
                            <td class="py-1.5 pe-2">{{ __('dom.') }} <a href="{{ route('admin.households.show', $r->household_id) }}" class="underline" wire:navigate>#{{ $r->household_id }}</a></td>
                            <td class="py-1.5 pe-2">{{ __('grant :id (:kind)', ['id' => $r->usage_grant_id, 'kind' => $r->grant?->kind->label()]) }}</td>
                            <td class="py-1.5 pe-2">{{ __('AI úloha :id · :status', ['id' => $r->ai_job_id ?? '–', 'status' => $r->aiJob?->status->label() ?? __('bez úlohy')]) }}</td>
                            <td class="py-1.5 whitespace-nowrap">{{ $r->reserved_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </flux:card>
    @elseif ($open)
        <flux:callout icon="check-circle" variant="success" class="text-sm"><flux:callout.text>{{ __('Žiadne otvorené rezervácie.') }}</flux:callout.text></flux:callout>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="household" :placeholder="__('ID domácnosti')" class="w-40" />
        <flux:select wire:model.live="source" class="w-48">
            <flux:select.option value="">{{ __('všetky zdroje') }}</flux:select.option>
            @foreach (UsageGrantSource::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="kind" class="w-48">
            <flux:select.option value="">{{ __('oba druhy') }}</flux:select.option>
            @foreach (UsageKind::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">{{ __('Domácnosť') }}</th><th class="py-1 pe-2">{{ __('Druh') }}</th><th class="py-1 pe-2">{{ __('Zdroj') }}</th><th class="py-1 pe-2 text-right">{{ __('Množstvo') }}</th><th class="py-1 pe-2 text-right">{{ __('Rezerv.') }}</th><th class="py-1 pe-2 text-right">{{ __('Spotreb.') }}</th><th class="py-1 pe-2 text-right">{{ __('Odobr.') }}</th><th class="py-1 pe-2 text-right">{{ __('Voľné') }}</th><th class="py-1 pe-2">{{ __('Platnosť') }}</th><th class="py-1 pe-2">{{ __('Poznámka / dôvod') }}</th><th class="py-1"></th></tr>
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
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $g->id }})">{{ $expanded === $g->id ? __('skryť') : __('ledger') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="calculator" wire:click="reconcile({{ $g->id }})" :title="__('Prepočítať počítadlá z ledgeru')" />
                        </td>
                    </tr>
                    @if ($expanded === $g->id)
                        <tr wire:key="ledger-{{ $g->id }}">
                            <td colspan="12" class="bg-zinc-50 p-2 dark:bg-zinc-800/50">
                                <table class="w-full text-xs">
                                    <thead class="text-left uppercase text-zinc-500"><tr><th class="py-0.5 pe-2">{{ __('Čas') }}</th><th class="py-0.5 pe-2">{{ __('Dôvod') }}</th><th class="py-0.5 pe-2 text-right">{{ __('Pohyb') }}</th><th class="py-0.5 pe-2">{{ __('Rezervácia') }}</th><th class="py-0.5 pe-2">{{ __('Kto') }}</th><th class="py-0.5 pe-2">{{ __('Kľúč') }}</th><th class="py-0.5">{{ __('Poznámka') }}</th></tr></thead>
                                    <tbody>
                                        @foreach ($this->entries as $e)
                                            <tr wire:key="entry-{{ $e->id }}">
                                                <td class="py-0.5 pe-2 whitespace-nowrap">{{ $e->created_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i:s') }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->reason->value }}</td>
                                                <td class="py-0.5 pe-2 text-right tabular-nums {{ $e->movement < 0 ? 'text-red-600' : ($e->movement > 0 ? 'text-green-700' : '') }}">{{ $e->movement > 0 ? '+' : '' }}{{ $e->movement }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->usage_reservation_id ?? '–' }}</td>
                                                <td class="py-0.5 pe-2">{{ $e->actor?->email ?? __('systém') }}</td>
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
                    <tr><td colspan="12" class="py-3 text-zinc-500">{{ __('Žiadne granty.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->grants->links() }}
    </flux:card>
</div>
