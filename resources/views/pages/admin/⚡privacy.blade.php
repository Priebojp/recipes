<?php

use App\Enums\PrivacyRequestStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Order;
use App\Models\PrivacyRequest;
use App\Models\WithdrawalRequest;
use App\Services\Privacy\PrivacyRequests;
use App\Services\Privacy\WithdrawalService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Data-subject requests (export, erasure, rectification) with deadlines, and online withdrawals with their
 * refund decision. Decisions are audited; refunds go through RefundService.
 */
new #[Layout('layouts::admin')] #[Title('Súkromie a odstúpenia')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $wstatus = 'received';

    public int $acting = 0;

    public string $note = '';

    public string $newStatus = '';

    public int $wacting = 0;

    public string $wnote = '';

    public string $worder = '';

    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return PrivacyRequest::query()
            ->with(['user:id,email', 'handler:id,email'])
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', [PrivacyRequestStatus::Received, PrivacyRequestStatus::InProgress]))
            ->when($this->status !== 'open' && $this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('deadline_at')
            ->paginate(25, pageName: 'requests');
    }

    #[Computed]
    public function withdrawals(): LengthAwarePaginator
    {
        return WithdrawalRequest::query()
            ->with(['order:id,household_id,product_snapshot,amount_cents,currency,status', 'refundCase:id,status,amount_cents,currency', 'handler:id,email'])
            ->when($this->wstatus !== '', fn ($q) => $q->where('status', $this->wstatus))
            ->latest('received_at')
            ->paginate(25, pageName: 'withdrawals');
    }

    public function start(int $id, string $status): void
    {
        $this->acting = $id;
        $this->newStatus = $status;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function apply(PrivacyRequests $requests): void
    {
        $this->authorize('platform-admin');
        $this->validate(['note' => ['required', 'string', 'min:3', 'max:1000'], 'newStatus' => ['required', 'in:in_progress,completed,rejected']]);
        $requests->update(PrivacyRequest::query()->findOrFail($this->acting), PrivacyRequestStatus::from($this->newStatus), $this->note, auth()->user());
        $this->reset('acting', 'note', 'newStatus');
        unset($this->requests);
        Flux::toast(variant: 'success', text: 'Žiadosť aktualizovaná.');
    }

    public function wstart(int $id): void
    {
        $this->wacting = $id;
        $this->wnote = '';
        $this->worder = '';
        $this->resetErrorBag();
    }

    public function attach(WithdrawalService $withdrawals): void
    {
        $this->authorize('platform-admin');
        $this->validate(['worder' => ['required', 'integer', 'exists:orders,id']]);
        $withdrawals->attachOrder(WithdrawalRequest::query()->findOrFail($this->wacting), Order::query()->findOrFail((int) $this->worder), auth()->user());
        unset($this->withdrawals);
        Flux::toast(variant: 'success', text: 'Objednávka priradená.');
    }

    public function refund(WithdrawalService $withdrawals): void
    {
        $this->authorize('platform-admin');
        $this->validate(['wnote' => ['required', 'string', 'min:3', 'max:1000']]);
        try {
            $withdrawals->refund(WithdrawalRequest::query()->findOrFail($this->wacting), auth()->user(), $this->wnote);
        } catch (InvalidArgumentException $e) {
            $this->addError('wnote', $e->getMessage());

            return;
        }
        $this->reset('wacting', 'wnote', 'worder');
        unset($this->withdrawals);
        Flux::toast(variant: 'success', text: 'Refundácia vykonaná, zákazník dostal e-mail.');
    }

    public function reject(WithdrawalService $withdrawals): void
    {
        $this->authorize('platform-admin');
        $this->validate(['wnote' => ['required', 'string', 'min:10', 'max:1000']]);
        try {
            $withdrawals->reject(WithdrawalRequest::query()->findOrFail($this->wacting), auth()->user(), $this->wnote);
        } catch (InvalidArgumentException $e) {
            $this->addError('wnote', $e->getMessage());

            return;
        }
        $this->reset('wacting', 'wnote', 'worder');
        unset($this->withdrawals);
        Flux::toast(variant: 'success', text: 'Odstúpenie zamietnuté s odôvodnením, zákazník dostal e-mail.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Súkromie a odstúpenia" subtitle="Žiadosti dotknutých osôb s lehotami a online odstúpenia od zmluvy s refund workflow. Bez obsahu receptov." />

    <flux:card class="space-y-3 overflow-x-auto" data-test="withdrawals">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" class="font-display">Odstúpenia od zmluvy</flux:heading>
            <flux:select wire:model.live="wstatus" class="w-48">
                <flux:select.option value="">všetky</flux:select.option>
                @foreach (WithdrawalStatus::cases() as $s) <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option> @endforeach
            </flux:select>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Číslo</th><th class="py-1 pe-2">Prijaté</th><th class="py-1 pe-2">E-mail</th><th class="py-1 pe-2">Objednávka</th><th class="py-1 pe-2">Stav</th><th class="py-1">Akcie</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->withdrawals as $w)
                    <tr wire:key="w-{{ $w->id }}" class="align-top">
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $w->reference }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $w->received_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}@if ($w->receipt_sent_at)<div class="text-xs text-zinc-500">potvrdenie odoslané</div>@endif</td>
                        <td class="py-1.5 pe-2 text-xs">{{ $w->email }}@if ($w->message)<div class="max-w-xs truncate text-zinc-500" title="{{ $w->message }}">{{ $w->message }}</div>@endif</td>
                        <td class="py-1.5 pe-2">
                            @if ($w->order)
                                <a href="{{ route('admin.orders.show', $w->order_id) }}" wire:navigate class="underline">#{{ $w->order_id }}</a> <span class="text-xs text-zinc-500">{{ $w->order->productName() }} · {{ App\Services\Billing\Catalog::formatCents($w->order->amount_cents, $w->order->currency) }} · {{ $w->order->status->label() }}</span>
                            @else
                                <flux:badge size="sm" color="amber">nespárované</flux:badge> <span class="text-xs text-zinc-500">{{ $w->order_reference }}</span>
                            @endif
                        </td>
                        <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$w->status->badgeColor()">{{ $w->status->label() }}</flux:badge>@if ($w->decision_note)<div class="max-w-xs text-xs text-zinc-500">{{ $w->decision_note }}</div>@endif</td>
                        <td class="py-1.5">
                            @if ($w->status === WithdrawalStatus::Received)
                                <flux:button size="xs" wire:click="wstart({{ $w->id }})" data-test="withdrawal-act-{{ $w->id }}">Rozhodnúť</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-zinc-500">Žiadne odstúpenia.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->withdrawals->links() }}

        @if ($wacting > 0)
            @php($w = WithdrawalRequest::query()->find($wacting))
            @if ($w)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="withdrawal-decision">
                    <flux:heading class="font-display">{{ $w->reference }}</flux:heading>
                    @unless ($w->order)
                        <div class="mt-2 flex items-end gap-2">
                            <flux:input wire:model="worder" label="Priradiť objednávku (ID)" class="w-40" />
                            <flux:button size="sm" wire:click="attach">Priradiť</flux:button>
                        </div>
                    @endunless
                    <flux:input wire:model="wnote" label="Poznámka k rozhodnutiu (ide zákazníkovi pri zamietnutí, do auditu vždy)" class="mt-2" data-test="withdrawal-note" />
                    <div class="mt-2 flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" wire:click="refund" :disabled="! $w->order" wire:confirm="Vrátiť celú zostávajúcu sumu a odobrať nevyužité jednotky aj Plus obdobie?" data-test="withdrawal-refund">Vrátiť celú sumu</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="reject" data-test="withdrawal-reject">Zamietnuť</flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="$set('wacting', 0)">Zavrieť</flux:button>
                    </div>
                </div>
            @endif
        @endif
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto" data-test="privacy-requests">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" class="font-display">Žiadosti dotknutých osôb</flux:heading>
            <flux:select wire:model.live="status" class="w-48">
                <flux:select.option value="open">otvorené</flux:select.option>
                <flux:select.option value="">všetky</flux:select.option>
                @foreach (PrivacyRequestStatus::cases() as $s) <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option> @endforeach
            </flux:select>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2">Subjekt</th><th class="py-1 pe-2">Prijatá</th><th class="py-1 pe-2">Lehota</th><th class="py-1 pe-2">Stav</th><th class="py-1">Akcie</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->requests as $r)
                    <tr wire:key="r-{{ $r->id }}" class="align-top">
                        <td class="py-1.5 pe-2">{{ $r->id }}</td>
                        <td class="py-1.5 pe-2">{{ $r->kind->label() }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ $r->user?->email ?? $r->subject_email }}@if ($r->household_id) · dom. #{{ $r->household_id }} @endif</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $r->received_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap {{ $r->isOverdue() ? 'text-red-600' : '' }}">{{ $r->deadline_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}</td>
                        <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$r->status->badgeColor()">{{ $r->status->label() }}</flux:badge>@if ($r->completion_evidence)<details class="text-xs text-zinc-500"><summary class="cursor-pointer">evidencia</summary><pre class="whitespace-pre-wrap">{{ $r->completion_evidence }}</pre></details>@endif</td>
                        <td class="py-1.5">
                            @if ($r->status->isOpen())
                                <div class="flex gap-1">
                                    <flux:button size="xs" wire:click="start({{ $r->id }}, 'in_progress')">V riešení</flux:button>
                                    <flux:button size="xs" wire:click="start({{ $r->id }}, 'completed')" data-test="request-complete-{{ $r->id }}">Vybavená</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="start({{ $r->id }}, 'rejected')">Zamietnuť</flux:button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-3 text-zinc-500">Žiadne žiadosti.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->requests->links() }}

        @if ($acting > 0)
            <div class="flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="request-decision">
                <flux:input wire:model="note" label="Poznámka / dôkaz vybavenia (#{{ $acting }} → {{ PrivacyRequestStatus::tryFrom($newStatus)?->label() }})" class="w-96" data-test="request-note" />
                <flux:button size="sm" variant="primary" wire:click="apply" data-test="request-apply">Uložiť</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="$set('acting', 0)">Zrušiť</flux:button>
            </div>
        @endif
    </flux:card>
</div>
