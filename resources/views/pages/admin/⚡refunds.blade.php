<?php

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Models\RefundCase;
use App\Services\Billing\Catalog;
use App\Support\StripeDashboard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * All refund, withdrawal and dispute cases; the decision itself happens on the order page.
 */
new #[Layout('layouts::admin')] #[Title('Refundácie')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $kind = '';

    public function updated(string $property): void
    {
        if ($property === 'status' && $this->status !== '' && RefundStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }
        if ($property === 'kind' && $this->kind !== '' && RefundKind::tryFrom($this->kind) === null) {
            $this->kind = '';
        }
        $this->resetPage();
    }

    #[Computed]
    public function cases(): LengthAwarePaginator
    {
        return RefundCase::query()
            ->with(['order:id,household_id,product_snapshot,amount_cents,currency', 'requester:id,email'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
            ->latest('id')
            ->paginate(25);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return RefundCase::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Refundácie a spory" subtitle="Každý prípad má sumu, dôvod, autora, odobraté jednotky a väzbu na objednávku a Stripe. Refundácie zo Stripe dashboardu čakajú na posúdenie." />

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="status" class="w-56">
            <flux:select.option value="">všetky stavy</flux:select.option>
            @foreach (RefundStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }} ({{ $this->counts[$case->value] ?? 0 }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="kind" class="w-56">
            <flux:select.option value="">všetky druhy</flux:select.option>
            @foreach (RefundKind::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Kedy</th><th class="py-1 pe-2">Objednávka</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2 text-right">Suma</th><th class="py-1 pe-2">Odobraté</th><th class="py-1 pe-2">Stav</th><th class="py-1 pe-2">Kto</th><th class="py-1">Dôvod</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->cases as $case)
                    <tr wire:key="case-{{ $case->id }}" class="align-top">
                        <td class="py-1.5 pe-2">{{ $case->id }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ ($case->processed_at ?? $case->created_at)->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 pe-2"><a href="{{ route('admin.orders.show', $case->order_id) }}" class="underline" wire:navigate>#{{ $case->order_id }}</a> <span class="text-xs text-zinc-500">{{ $case->order?->productName() }} · dom. #{{ $case->household_id }}</span></td>
                        <td class="py-1.5 pe-2">{{ $case->kind->label() }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($case->amount_cents, $case->currency) }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ collect($case->units_revoked ?? [])->map(fn ($n, $k) => $n.' × '.$k)->implode(', ') ?: '–' }}@if ($case->revoke_entitlement) · Plus @endif</td>
                        <td class="py-1.5 pe-2">
                            <flux:badge size="sm" :color="$case->status->badgeColor()">{{ $case->status->label() }}</flux:badge>
                            @if ($case->stripe_refund_id)<a href="{{ StripeDashboard::refund($case->stripe_refund_id) }}" class="ms-1 text-xs underline" target="_blank" rel="noopener noreferrer">Stripe</a>@endif
                        </td>
                        <td class="py-1.5 pe-2 text-xs">{{ $case->requester?->email ?? 'systém' }}</td>
                        <td class="py-1.5 max-w-xs truncate text-xs" title="{{ $case->reason }}">{{ $case->reason }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-3 text-zinc-500">Žiadne prípady.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->cases->links() }}
    </flux:card>
</div>
