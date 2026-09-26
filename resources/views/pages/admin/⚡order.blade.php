<?php

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\UsageKind;
use App\Models\Order;
use App\Models\RefundCase;
use App\Services\Billing\Catalog;
use App\Services\Billing\RefundService;
use App\Support\Money;
use App\Support\StripeDashboard;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One order: snapshot, Stripe references, what it granted and the refund workflow (specification chapter 7).
 * The administrator names the amount and the unused units to take back; nothing is estimated from the amount.
 */
new #[Layout('layouts::admin')] #[Title('Objednávka')] class extends Component {
    public Order $order;

    public string $refund_kind = RefundKind::Goodwill->value;

    public string $refund_amount = '';

    public string $refund_text = '0';

    public string $refund_images = '0';

    public bool $refund_revoke_plus = false;

    public string $refund_key = '';

    public string $refund_reason = '';

    public ?int $review_case = null;

    public string $review_text = '0';

    public string $review_images = '0';

    public bool $review_revoke_plus = false;

    public string $review_note = '';

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->refund_amount = number_format(max(0, $order->amount_cents - app(RefundService::class)->refundedCents($order)) / 100, 2, ',', '');
    }

    #[Computed]
    public function entitlements(): Collection
    {
        return $this->order->entitlements()->with('planVersion:id,name')->orderBy('starts_at')->get();
    }

    #[Computed]
    public function grants(): Collection
    {
        $entitlementIds = $this->entitlements->pluck('id');

        return \App\Models\UsageGrant::query()
            ->where(fn ($q) => $q->where('order_id', $this->order->id)->orWhereIn('paid_entitlement_id', $entitlementIds))
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function refunds(): Collection
    {
        return $this->order->refundCases()->with('requester:id,email')->orderByDesc('id')->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function revocable(): array
    {
        return app(RefundService::class)->revocableUnits($this->order);
    }

    #[Computed]
    public function refundedCents(): int
    {
        return app(RefundService::class)->refundedCents($this->order);
    }

    public function refund(RefundService $refunds): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'refund_kind' => ['required', 'in:withdrawal,complaint,goodwill'],
            'refund_amount' => ['required', 'string', 'max:20'],
            'refund_text' => ['required', 'integer', 'min:0'],
            'refund_images' => ['required', 'integer', 'min:0'],
            'refund_key' => ['nullable', 'string', 'max:100'],
            'refund_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $amount = Money::parseEurToCents($validated['refund_amount']);
        if ($amount === null || $amount < 1) {
            $this->addError('refund_amount', 'Zadaj sumu v eurách, napr. 3,99.');

            return;
        }

        $units = array_filter([UsageKind::Text->value => (int) $validated['refund_text'], UsageKind::ImageStandard->value => (int) $validated['refund_images']]);

        try {
            $case = $refunds->request($this->order, RefundKind::from($validated['refund_kind']), $amount, $validated['refund_reason'], $units, $this->refund_revoke_plus, auth()->user(), $validated['refund_key'] ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('refund_amount', $e->getMessage());

            return;
        }

        $this->order->refresh();
        unset($this->refunds, $this->grants, $this->entitlements, $this->revocable, $this->refundedCents);
        $this->reset(['refund_text', 'refund_images', 'refund_key', 'refund_reason', 'refund_revoke_plus']);
        $this->refund_text = '0';
        $this->refund_images = '0';

        if ($case->status === RefundStatus::Processed) {
            Flux::toast(variant: 'success', text: "Refundácia #{$case->id} spracovaná (Stripe {$case->stripe_refund_id}).");
        } else {
            Flux::toast(variant: 'danger', text: "Refundácia #{$case->id} zlyhala v Stripe: {$case->error}. Nič sa neodobralo.");
        }
    }

    public function startReview(int $caseId): void
    {
        $this->review_case = $caseId;
        $this->reset(['review_text', 'review_images', 'review_revoke_plus', 'review_note']);
        $this->review_text = '0';
        $this->review_images = '0';
    }

    public function review(RefundService $refunds): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'review_text' => ['required', 'integer', 'min:0'],
            'review_images' => ['required', 'integer', 'min:0'],
            'review_note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $case = RefundCase::query()->where('order_id', $this->order->id)->findOrFail($this->review_case);

        try {
            $refunds->review($case, [UsageKind::Text->value => (int) $validated['review_text'], UsageKind::ImageStandard->value => (int) $validated['review_images']], $this->review_revoke_plus, $validated['review_note'], auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('review_note', $e->getMessage());

            return;
        }

        $this->review_case = null;
        $this->order->refresh();
        unset($this->refunds, $this->grants, $this->entitlements, $this->revocable);
        Flux::toast(variant: 'success', text: 'Refundácia posúdená a zapísaná do auditu.');
    }
}; ?>

<div class="space-y-6">
    @php($tz = config('recipes.default_timezone'))
    @php($snap = $this->order->product_snapshot)

    <x-page-header :title="'Objednávka #'.$this->order->id" :subtitle="$this->order->productName().' · '.Catalog::formatCents($this->order->amount_cents, $this->order->currency)" :back="route('admin.orders')">
        <flux:badge :color="$this->order->status->badgeColor()">{{ $this->order->status->label() }}</flux:badge>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-2" data-test="order-summary">
            <flux:heading size="lg" class="font-display">Nákup</flux:heading>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-500">Domácnosť</dt><dd><a href="{{ route('admin.households.show', $this->order->household_id) }}" class="underline" wire:navigate>#{{ $this->order->household_id }} {{ $this->order->household?->name }}</a></dd>
                <dt class="text-zinc-500">Produkt</dt><dd>{{ $snap['name'] ?? '' }} · {{ $snap['code'] ?? '' }} v{{ $snap['version'] ?? '?' }}</dd>
                @if (($snap['type'] ?? '') === 'plan')
                    <dt class="text-zinc-500">Obsah</dt><dd>{{ $snap['uses_per_period']['text'] ?? 0 }} textov / {{ $snap['uses_per_period']['image_standard'] ?? 0 }} obrázkov za obdobie · {{ $snap['interval'] ?? '' }}</dd>
                @else
                    <dt class="text-zinc-500">Obsah</dt><dd>{{ $snap['unit_count'] ?? 0 }} × {{ UsageKind::tryFrom($snap['unit_kind'] ?? '')?->label() }}</dd>
                @endif
                <dt class="text-zinc-500">Suma</dt><dd>{{ Catalog::formatCents($this->order->amount_cents, $this->order->currency) }}@if ($this->refundedCents > 0) · refundované {{ Catalog::formatCents($this->refundedCents, $this->order->currency) }}@endif</dd>
                <dt class="text-zinc-500">Vytvorená</dt><dd>{{ $this->order->created_at->setTimezone($tz)->format('d.m.Y H:i') }}</dd>
                <dt class="text-zinc-500">Zaplatená</dt><dd>{{ $this->order->paid_at?->setTimezone($tz)->format('d.m.Y H:i') ?? '–' }}</dd>
                @if ($this->order->failure_reason)<dt class="text-zinc-500">Chyba</dt><dd>{{ $this->order->failure_reason }}</dd>@endif
            </dl>
        </flux:card>

        <flux:card class="space-y-2" data-test="order-stripe">
            <flux:heading size="lg" class="font-display">Stripe</flux:heading>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-500">Checkout</dt><dd class="truncate font-mono text-xs">{{ $this->order->stripe_checkout_session_id ?? '–' }}</dd>
                <dt class="text-zinc-500">Platba</dt><dd class="truncate text-xs">@if ($this->order->stripe_payment_intent_id)<a href="{{ StripeDashboard::paymentIntent($this->order->stripe_payment_intent_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $this->order->stripe_payment_intent_id }}</a>@else – @endif</dd>
                <dt class="text-zinc-500">Faktúra</dt><dd class="truncate text-xs">@if ($this->order->stripe_invoice_id)<a href="{{ StripeDashboard::invoice($this->order->stripe_invoice_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $this->order->stripe_invoice_id }}</a>@else – @endif</dd>
                <dt class="text-zinc-500">Predplatné</dt><dd class="truncate text-xs">@if ($this->order->stripe_subscription_id)<a href="{{ StripeDashboard::subscription($this->order->stripe_subscription_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $this->order->stripe_subscription_id }}</a>@else – @endif</dd>
                <dt class="text-zinc-500">Zákazník</dt><dd class="truncate text-xs">@if ($this->order->billingAccount?->stripe_id)<a href="{{ StripeDashboard::customer($this->order->billingAccount->stripe_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $this->order->billingAccount->stripe_id }}</a>@else – @endif</dd>
            </dl>
            <flux:text class="text-xs">Doklady vystavuje Stripe; tu sa nemenia ani nemažú.</flux:text>
        </flux:card>
    </div>

    @if ($this->entitlements->isNotEmpty())
        <flux:card class="space-y-2 overflow-x-auto" data-test="order-entitlements">
            <flux:heading size="lg" class="font-display">Zaplatené obdobia</flux:heading>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @foreach ($this->entitlements as $e)
                        <tr wire:key="ent-{{ $e->id }}">
                            <td class="py-1.5 pe-2">{{ $e->planVersion->name }}</td>
                            <td class="py-1.5 pe-2 whitespace-nowrap">{{ $e->starts_at->setTimezone($tz)->format('d.m.Y') }} – {{ $e->ends_at->setTimezone($tz)->format('d.m.Y') }}</td>
                            <td class="py-1.5 pe-2 text-xs">{{ $e->stripe_invoice_id }}</td>
                            <td class="py-1.5 text-right">@if ($e->revoked_at)<flux:badge color="red" size="sm">odobrané</flux:badge>@elseif ($e->isActiveAt(now()))<flux:badge color="green" size="sm">aktívne</flux:badge>@else<flux:badge color="zinc" size="sm">skončené</flux:badge>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </flux:card>
    @endif

    <flux:card class="space-y-2 overflow-x-auto" data-test="order-grants">
        <flux:heading size="lg" class="font-display">Udelené použitia</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2 text-right">Množstvo</th><th class="py-1 pe-2 text-right">Spotrebované</th><th class="py-1 pe-2 text-right">Rezervované</th><th class="py-1 pe-2 text-right">Odobrané</th><th class="py-1 pe-2 text-right">Nevyužité</th><th class="py-1">Platnosť</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->grants as $g)
                    <tr wire:key="grant-{{ $g->id }}">
                        <td class="py-1.5 pe-2">{{ $g->id }}</td>
                        <td class="py-1.5 pe-2">{{ $g->kind->label() }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->consumed_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->reserved_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->revoked_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right font-medium tabular-nums">{{ $g->available() }}</td>
                        <td class="py-1.5 whitespace-nowrap text-xs">{{ $g->valid_from->setTimezone($tz)->format('d.m.Y') }} – {{ $g->expires_at?->setTimezone($tz)->format('d.m.Y') ?? 'bez expirácie' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-2 text-zinc-500">Zatiaľ žiadne granty (objednávka nie je zaplatená alebo obdobie ešte nezačalo).</td></tr>
                @endforelse
            </tbody>
        </table>
        <flux:text class="text-xs">Refundácia môže odobrať najviac nevyužité jednotky tejto objednávky: {{ collect($this->revocable)->map(fn ($n, $k) => $n.' × '.UsageKind::from($k)->label())->implode(', ') ?: 'žiadne' }}. Iné balíky domácnosti sa nedotýkajú.</flux:text>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto" data-test="order-refunds">
        <flux:heading size="lg" class="font-display">Refundácie a spory</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">Druh</th><th class="py-1 pe-2 text-right">Suma</th><th class="py-1 pe-2">Odobraté</th><th class="py-1 pe-2">Stav</th><th class="py-1 pe-2">Kto / kedy</th><th class="py-1">Dôvod</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->refunds as $case)
                    <tr wire:key="case-{{ $case->id }}" class="align-top">
                        <td class="py-1.5 pe-2">{{ $case->id }}@if ($case->stripe_refund_id) <a href="{{ StripeDashboard::refund($case->stripe_refund_id) }}" class="text-xs underline" target="_blank" rel="noopener noreferrer">Stripe</a>@endif @if ($case->stripe_dispute_id) <a href="{{ StripeDashboard::dispute($case->stripe_dispute_id) }}" class="text-xs underline" target="_blank" rel="noopener noreferrer">spor</a>@endif</td>
                        <td class="py-1.5 pe-2">{{ $case->kind->label() }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($case->amount_cents, $case->currency) }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ collect($case->units_revoked ?? [])->map(fn ($n, $k) => $n.' × '.(UsageKind::tryFrom($k)?->label() ?? $k))->implode(', ') ?: '–' }}@if ($case->revoke_entitlement) · Plus obdobie @endif</td>
                        <td class="py-1.5 pe-2">
                            <flux:badge size="sm" :color="$case->status->badgeColor()">{{ $case->status->label() }}</flux:badge>
                            @if ($case->status === RefundStatus::NeedsReview)
                                <flux:button size="xs" variant="ghost" class="ms-1" wire:click="startReview({{ $case->id }})" data-test="start-review-{{ $case->id }}">Posúdiť</flux:button>
                            @endif
                            @if ($case->error)<div class="text-xs text-red-600">{{ $case->error }}</div>@endif
                        </td>
                        <td class="py-1.5 pe-2 text-xs">{{ $case->requester?->email ?? 'systém' }}<br>{{ ($case->processed_at ?? $case->created_at)->setTimezone($tz)->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 max-w-xs text-xs">{{ $case->reason }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-2 text-zinc-500">Žiadne refundácie.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($review_case !== null)
            <form wire:submit="review" class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20" data-test="review-form">
                <flux:heading size="sm">Posúdenie refundácie #{{ $review_case }} zo Stripe</flux:heading>
                <flux:text class="text-xs">Peniaze už odišli. Rozhodni, koľko nevyužitých jednotiek tejto objednávky sa odoberie (môže byť 0) a či končí Plus obdobie.</flux:text>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="review_text" type="number" min="0" label="Odobrať textových" />
                    <flux:input wire:model="review_images" type="number" min="0" label="Odobrať obrázkov" />
                    <flux:checkbox wire:model="review_revoke_plus" label="Odobrať zaplatené Plus obdobie" class="mt-6" />
                </div>
                <flux:textarea wire:model="review_note" rows="2" label="Poznámka (ide do auditu)" />
                <div class="flex gap-2">
                    <flux:button type="submit" size="sm" variant="primary">Uložiť posúdenie</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('review_case', null)">Zrušiť</flux:button>
                </div>
            </form>
        @endif
    </flux:card>

    @if ($this->order->status->isSettled() && $this->order->stripe_payment_intent_id && $this->refundedCents < $this->order->amount_cents)
        <flux:card class="space-y-3" data-test="refund-form">
            <form wire:submit="refund" class="space-y-3">
                <flux:heading size="lg" class="font-display">Refundovať</flux:heading>
                <flux:text class="text-xs">Refund prebehne v Stripe s idempotentným kľúčom; potom sa odoberú iba nevyužité jednotky, ktoré tu určíš. Zlyhanie v Stripe neodoberie nič. Spotreba ani pôvodný doklad sa nemažú.</flux:text>
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:select wire:model="refund_kind" label="Druh">
                        <flux:select.option value="withdrawal">{{ RefundKind::Withdrawal->label() }}</flux:select.option>
                        <flux:select.option value="complaint">{{ RefundKind::Complaint->label() }}</flux:select.option>
                        <flux:select.option value="goodwill">{{ RefundKind::Goodwill->label() }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="refund_amount" label="Suma (EUR)" placeholder="3,99" />
                    <flux:input wire:model="refund_text" type="number" min="0" :max="$this->revocable['text'] ?? 0" label="Odobrať textových (max {{ $this->revocable['text'] ?? 0 }})" />
                    <flux:input wire:model="refund_images" type="number" min="0" :max="$this->revocable['image_standard'] ?? 0" label="Odobrať obrázkov (max {{ $this->revocable['image_standard'] ?? 0 }})" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="refund_key" label="Idempotentný kľúč (číslo tiketu, voliteľné)" />
                    @if ($this->entitlements->whereNull('revoked_at')->isNotEmpty())
                        <flux:checkbox wire:model="refund_revoke_plus" label="Odobrať aj zaplatené Plus obdobie (ročný plán tým zastaví ďalšie mesačné granty)" class="mt-6" />
                    @endif
                </div>
                <flux:textarea wire:model="refund_reason" rows="2" label="Dôvod (povinný, ide do auditu)" />
                <flux:button type="submit" variant="danger" size="sm" wire:confirm="Odoslať refundáciu do Stripe? Peniaze sa vrátia zákazníkovi.">Refundovať</flux:button>
            </form>
        </flux:card>
    @endif
</div>
