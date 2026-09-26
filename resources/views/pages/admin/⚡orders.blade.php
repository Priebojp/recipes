<?php

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Billing\Catalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every purchase attempt with its immutable product snapshot and payment state. The detail page holds the refund workflow.
 */
new #[Layout('layouts::admin')] #[Title('Objednávky')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $search = '';

    public function updated(string $property): void
    {
        if ($property === 'status' && $this->status !== '' && OrderStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }
        if ($property === 'kind' && $this->kind !== '' && OrderKind::tryFrom($this->kind) === null) {
            $this->kind = '';
        }
        $this->resetPage();
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Order::query()
            ->with(['household:id,name,owner_user_id', 'household.owner:id,email'])
            ->withSum(['refundCases as refunded_cents' => fn (Builder $q) => $q->whereIn('status', ['processed', 'needs_review', 'reviewed'])], 'amount_cents')
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->kind !== '', fn (Builder $q) => $q->where('kind', $this->kind))
            ->when($term !== '', function (Builder $q) use ($term) {
                $q->where(function (Builder $w) use ($term) {
                    $w->where('stripe_checkout_session_id', 'like', "%{$term}%")
                        ->orWhere('stripe_payment_intent_id', 'like', "%{$term}%")
                        ->orWhere('stripe_invoice_id', 'like', "%{$term}%")
                        ->orWhere('stripe_subscription_id', 'like', "%{$term}%")
                        ->orWhereHas('household', fn (Builder $h) => $h->where('name', 'like', "%{$term}%")->orWhereHas('owner', fn (Builder $u) => $u->where('email', 'like', "%{$term}%")));
                    if (ctype_digit($term)) {
                        $w->orWhere('id', (int) $term)->orWhere('household_id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->paginate(25);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Balíky a objednávky" subtitle="Obsah nákupu, úhrada a refundácie. Ceny sú snímka katalógu z času nákupu; neskoršia zmena cenníka ich nemení." />

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="ID objednávky / domácnosti, e-mail, Stripe ID" clearable class="max-w-md" />
        <flux:select wire:model.live="status" class="w-56">
            <flux:select.option value="">všetky stavy</flux:select.option>
            @foreach (OrderStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }} ({{ $this->counts[$case->value] ?? 0 }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="kind" class="w-40">
            <flux:select.option value="">predplatné aj balíky</flux:select.option>
            <flux:select.option value="subscription">predplatné</flux:select.option>
            <flux:select.option value="addon">balíky</flux:select.option>
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Vytvorená</th><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Produkt</th><th class="py-1 pe-2 text-right">Suma</th><th class="py-1 pe-2 text-right">Refundované</th><th class="py-1 pe-2">Stav</th><th class="py-1">Zaplatená</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->orders as $order)
                    <tr wire:key="order-{{ $order->id }}">
                        <td class="py-1.5 pe-2"><a href="{{ route('admin.orders.show', $order) }}" class="underline" wire:navigate>#{{ $order->id }}</a></td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $order->created_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 pe-2"><a href="{{ route('admin.households.show', $order->household_id) }}" class="underline" wire:navigate>#{{ $order->household_id }}</a> <span class="text-xs text-zinc-500">{{ $order->household?->owner?->email }}</span></td>
                        <td class="py-1.5 pe-2">{{ $order->productName() }} <span class="text-xs text-zinc-500">v{{ $order->product_snapshot['version'] ?? '?' }}</span></td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($order->amount_cents, $order->currency) }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $order->refunded_cents ? Catalog::formatCents((int) $order->refunded_cents, $order->currency) : '–' }}</td>
                        <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$order->status->badgeColor()">{{ $order->status->label() }}</flux:badge></td>
                        <td class="py-1.5 whitespace-nowrap">{{ $order->paid_at?->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') ?? '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-3 text-zinc-500">Žiadne objednávky.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->orders->links() }}
    </flux:card>
</div>
