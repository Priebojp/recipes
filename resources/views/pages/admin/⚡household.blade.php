<?php

use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\Compensations;
use App\Services\Admin\HouseholdModeration;
use App\Services\Billing\Catalog;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\PlanStatus;
use App\Support\Money;
use App\Support\StripeDashboard;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One household for support: plan, subscription, paid periods, grants, orders, refunds – and the audited actions an
 * administrator may take (compensation uses, time-limited Plus, blocking, subscription sync / renewal).
 * No recipes, no diner names.
 */
new #[Layout('layouts::admin')] #[Title('Domácnosť')] class extends Component {
    public Household $household;

    public string $comp_kind = 'text';

    public string $comp_quantity = '1';

    public string $comp_expires = '';

    public string $comp_key = '';

    public string $comp_reason = '';

    public string $plus_plan = '';

    public string $plus_from = '';

    public string $plus_to = '';

    public string $plus_reason = '';

    public string $block_reason = '';

    public string $subscription_reason = '';

    public function mount(Household $household): void
    {
        $this->household = $household;
        $today = CarbonImmutable::now($this->timezone());
        $this->plus_from = $today->toDateString();
        $this->plus_to = $today->addMonth()->toDateString();
        $this->plus_plan = (string) (app(Catalog::class)->plans()->first()?->code ?? '');
    }

    public function timezone(): string
    {
        return (string) config('recipes.default_timezone');
    }

    /** @return array{is_plus: bool, grace: bool, current: ?PaidEntitlement, paid_through: ?\Carbon\CarbonInterface, subscription: ?\Laravel\Cashier\Subscription} */
    #[Computed]
    public function status(): array
    {
        $plans = app(PlanStatus::class);

        return [
            'is_plus' => $plans->isPlus($this->household),
            'grace' => $plans->inRenewalGrace($this->household),
            'current' => $plans->current($this->household)?->load('planVersion'),
            'paid_through' => $plans->paidThrough($this->household),
            'subscription' => $plans->subscription($this->household),
        ];
    }

    #[Computed]
    public function entitlements(): Collection
    {
        return $this->household->paidEntitlements()->with('planVersion:id,name,interval')->orderByDesc('ends_at')->get();
    }

    #[Computed]
    public function grants(): Collection
    {
        return $this->household->usageGrants()->orderByDesc('id')->limit(100)->get();
    }

    #[Computed]
    public function orders(): Collection
    {
        return $this->household->orders()->orderByDesc('id')->limit(50)->get();
    }

    #[Computed]
    public function refunds(): Collection
    {
        return $this->household->refundCases()->orderByDesc('id')->get();
    }

    /** @return array{jobs: int, failed: int, cost_micro: int} */
    #[Computed]
    public function ai(): array
    {
        $row = AiJob::query()
            ->where('household_id', $this->household->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("count(*) as jobs, sum(case when status = 'failed' then 1 else 0 end) as failed, coalesce(sum(estimated_cost_micro_usd), 0) as cost_micro")
            ->toBase()
            ->first();

        return ['jobs' => (int) ($row->jobs ?? 0), 'failed' => (int) ($row->failed ?? 0), 'cost_micro' => (int) ($row->cost_micro ?? 0)];
    }

    /** @return array<string, array{available: int, total: int}> */
    #[Computed]
    public function balances(): array
    {
        $out = [];
        foreach (UsageKind::cases() as $kind) {
            $valid = $this->grants->filter(fn ($g) => $g->kind === $kind && $g->isValidAt(now()));
            $out[$kind->value] = ['available' => (int) $valid->sum(fn ($g) => $g->available()), 'total' => (int) $valid->sum(fn ($g) => $g->effectiveQuantity())];
        }

        return $out;
    }

    #[Computed]
    public function plans(): Collection
    {
        return app(Catalog::class)->plans();
    }

    public function grantUses(Compensations $compensations): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'comp_kind' => ['required', 'in:text,image_standard'],
            'comp_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'comp_expires' => ['nullable', 'date', 'after:now'],
            'comp_key' => ['nullable', 'string', 'max:100'],
            'comp_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $expires = $validated['comp_expires'] !== '' && $validated['comp_expires'] !== null
            ? CarbonImmutable::parse($validated['comp_expires'], $this->timezone())->endOfDay()->utc()
            : null;

        $grant = $compensations->grantUses($this->household, UsageKind::from($validated['comp_kind']), (int) $validated['comp_quantity'], $validated['comp_reason'], $expires, $validated['comp_key'] ?: null, auth()->user());

        $this->reset(['comp_quantity', 'comp_expires', 'comp_key', 'comp_reason']);
        $this->comp_quantity = '1';
        unset($this->grants, $this->balances);
        Flux::toast(variant: 'success', text: $grant->wasRecentlyCreated ? __('Kompenzácia #:id vystavená.', ['id' => $grant->id]) : __('Grant s týmto kľúčom už existuje (#:id); nič nové sa nevystavilo.', ['id' => $grant->id]));
    }

    public function grantPlus(Compensations $compensations): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'plus_plan' => ['required', 'string'],
            'plus_from' => ['required', 'date'],
            'plus_to' => ['required', 'date', 'after:plus_from'],
            'plus_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $plan = PlanVersion::query()->active()->where('code', $validated['plus_plan'])->orderByDesc('version')->first();
        if ($plan === null) {
            $this->addError('plus_plan', __('Neznámy plán.'));

            return;
        }

        $from = CarbonImmutable::parse($validated['plus_from'], $this->timezone())->startOfDay()->utc();
        $to = CarbonImmutable::parse($validated['plus_to'], $this->timezone())->startOfDay()->utc();

        $compensations->grantPlus($this->household, $plan, $from, $to, $validated['plus_reason'], auth()->user());

        $this->reset('plus_reason');
        unset($this->status, $this->entitlements, $this->grants, $this->balances);
        Flux::toast(variant: 'success', text: __('Plus obdobie udelené. Nie je to platba – objednávka ani doklad nevznikli.'));
    }

    public function revokePlus(int $entitlementId, Compensations $compensations): void
    {
        $this->authorize('platform-admin');
        $entitlement = $this->household->paidEntitlements()->findOrFail($entitlementId);

        try {
            $compensations->revokePlus($entitlement, 'Ukončené administrátorom v detaile domácnosti', auth()->user());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->status, $this->entitlements);
        Flux::toast(text: __('Kompenzačné Plus obdobie ukončené.'));
    }

    public function block(HouseholdModeration $moderation): void
    {
        $this->authorize('platform-admin');
        $this->validate(['block_reason' => ['required', 'string', 'min:5', 'max:500']]);

        $moderation->block($this->household, $this->block_reason, auth()->user());
        $this->household->refresh();
        $this->reset('block_reason');
        Flux::toast(variant: 'success', text: __('Domácnosť je blokovaná: bez nových AI úloh a nákupov. Recepty a nároky zostávajú.'));
    }

    public function unblock(HouseholdModeration $moderation): void
    {
        $this->authorize('platform-admin');
        $this->validate(['block_reason' => ['required', 'string', 'min:5', 'max:500']]);

        $moderation->unblock($this->household, $this->block_reason, auth()->user());
        $this->household->refresh();
        $this->reset('block_reason');
        Flux::toast(variant: 'success', text: __('Blokovanie zrušené.'));
    }

    public function syncSubscription(StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $subscription = $this->status['subscription'];
        if ($subscription === null) {
            return;
        }

        $before = $subscription->only(['stripe_status', 'ends_at']);
        $gateway->syncSubscription($subscription);
        $audit->record('billing.subscription.synced', $subscription, $before, $subscription->fresh()->only(['stripe_status', 'ends_at']), 'Ručná synchronizácia zo Stripe');

        unset($this->status);
        Flux::toast(text: __('Predplatné synchronizované zo Stripe.'));
    }

    public function cancelRenewal(StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $this->validate(['subscription_reason' => ['required', 'string', 'min:5', 'max:500']]);
        $subscription = $this->status['subscription'];
        if ($subscription === null || $subscription->canceled()) {
            return;
        }

        $gateway->cancelRenewal($subscription);
        $audit->record('billing.subscription.renewal_canceled', $subscription, [], ['stripe_id' => $subscription->stripe_id], $this->subscription_reason);

        $this->reset('subscription_reason');
        unset($this->status);
        Flux::toast(text: __('Obnovovanie zrušené; Plus platí do konca zaplateného obdobia.'));
    }

    public function resumeRenewal(StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $this->validate(['subscription_reason' => ['required', 'string', 'min:5', 'max:500']]);
        $subscription = $this->status['subscription'];
        if ($subscription === null || ! $subscription->onGracePeriod()) {
            return;
        }

        $gateway->resumeRenewal($subscription);
        $audit->record('billing.subscription.renewal_resumed', $subscription, [], ['stripe_id' => $subscription->stripe_id], $this->subscription_reason);

        $this->reset('subscription_reason');
        unset($this->status);
        Flux::toast(text: __('Obnovovanie znova zapnuté.'));
    }
}; ?>

<div class="space-y-6">
    @php($status = $this->status)
    @php($tz = $this->timezone())
    @php($account = $this->household->billingAccount)

    <x-page-header :title="__('Domácnosť #:id – :name', ['id' => $this->household->id, 'name' => $this->household->name])" :subtitle="__('Podpora bez obsahu receptov a mien členov. Každá akcia tu má dôvod a audit.')" :back="route('admin.households')">
        @if ($this->household->isBlocked())
            <flux:badge color="red" icon="no-symbol">{{ __('blokovaná') }}</flux:badge>
        @endif
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-2" data-test="household-plan">
            <div class="flex items-center justify-between">
                <flux:heading size="lg" class="font-display">{{ $status['is_plus'] ? 'Plus' : 'Free' }}</flux:heading>
                @if ($status['grace'])<flux:badge color="amber" size="sm">{{ __('obnova zlyhala') }}</flux:badge>
                @elseif ($status['subscription']?->onGracePeriod())<flux:badge color="zinc" size="sm">{{ __('bez obnovy') }}</flux:badge>
                @elseif ($status['is_plus'])<flux:badge color="green" size="sm">{{ __('aktívne') }}</flux:badge>@endif
            </div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                @if ($status['current'])
                    <dt class="text-zinc-500">{{ __('Plán') }}</dt><dd>{{ $status['current']->planVersion->name }} v{{ $status['current']->planVersion->version }}@if ($status['current']->order_id === null) · <span class="text-amber-600">{{ __('kompenzácia') }}</span>@endif</dd>
                @endif
                <dt class="text-zinc-500">{{ __('Zaplatené do') }}</dt><dd>{{ $status['paid_through']?->setTimezone($tz)->format('d.m.Y H:i') ?? '–' }}</dd>
                <dt class="text-zinc-500">{{ __('Vlastník') }}</dt><dd>{{ $this->household->owner?->email }} {!! $this->household->owner?->email_verified_at ? '<span class="text-xs text-green-700">'.__('overený').'</span>' : '<span class="text-xs text-amber-600">'.__('neoverený').'</span>' !!}</dd>
                <dt class="text-zinc-500">{{ __('Časová zóna') }}</dt><dd>{{ $this->household->timezone }}</dd>
                <dt class="text-zinc-500">{{ __('Vytvorená') }}</dt><dd>{{ $this->household->created_at?->setTimezone($tz)->format('d.m.Y') }}</dd>
            </dl>
        </flux:card>

        <flux:card class="space-y-2" data-test="household-subscription">
            <flux:heading size="lg" class="font-display">{{ __('Predplatné (Stripe)') }}</flux:heading>
            @if ($status['subscription'])
                @php($sub = $status['subscription'])
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-zinc-500">{{ __('Stav') }}</dt><dd><code>{{ $sub->stripe_status }}</code>@if ($sub->ends_at) · {{ __('končí :date', ['date' => $sub->ends_at->setTimezone($tz)->format('d.m.Y')]) }}@endif</dd>
                    <dt class="text-zinc-500">{{ __('Subscription') }}</dt><dd class="truncate"><a href="{{ StripeDashboard::subscription($sub->stripe_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $sub->stripe_id }}</a></dd>
                    <dt class="text-zinc-500">{{ __('Price') }}</dt><dd class="truncate font-mono text-xs">{{ $sub->stripe_price }}</dd>
                    <dt class="text-zinc-500">{{ __('Zákazník') }}</dt><dd class="truncate"><a href="{{ StripeDashboard::customer($account?->stripe_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $account?->stripe_id }}</a></dd>
                </dl>
                <div class="space-y-2 pt-1">
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="syncSubscription" data-test="sync-subscription">{{ __('Synchronizovať zo Stripe') }}</flux:button>
                    @unless ($sub->ended())
                        <flux:input wire:model="subscription_reason" size="sm" :placeholder="__('Dôvod (povinný pre zmenu obnovovania)')" />
                        @if ($sub->onGracePeriod())
                            <flux:button size="sm" wire:click="resumeRenewal" data-test="admin-resume-renewal">{{ __('Obnovovať znova') }}</flux:button>
                        @elseif (! $sub->canceled())
                            <flux:button size="sm" variant="danger" wire:click="cancelRenewal" wire:confirm="{{ __('Zrušiť automatické obnovovanie? Zaplatené obdobie sa neskracuje.') }}" data-test="admin-cancel-renewal">{{ __('Zrušiť obnovovanie') }}</flux:button>
                        @endif
                    @endunless
                </div>
            @else
                <flux:text class="text-sm">{{ __('Bez Cashier predplatného.') }}@if ($account?->stripe_id) {!! __('Zákazník :link.', ['link' => '<a href="'.StripeDashboard::customer($account->stripe_id).'" class="underline" target="_blank" rel="noopener noreferrer">'.e($account->stripe_id).'</a>']) !!}@endif</flux:text>
            @endif
        </flux:card>

        <flux:card class="space-y-2" data-test="household-usage">
            <flux:heading size="lg" class="font-display">AI</flux:heading>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                @foreach (UsageKind::cases() as $kind)
                    <dt class="text-zinc-500">{{ ucfirst($kind->label()) }}</dt><dd>{{ __(':available voľných z :total platných', ['available' => $this->balances[$kind->value]['available'], 'total' => $this->balances[$kind->value]['total']]) }}</dd>
                @endforeach
                <dt class="text-zinc-500">{{ __('Úlohy 30 dní') }}</dt><dd>{{ $this->ai['jobs'] }} · {{ __(':count chýb', ['count' => $this->ai['failed']]) }} · {{ Money::microUsd($this->ai['cost_micro']) }}</dd>
            </dl>
            <flux:text class="text-xs"><a href="{{ route('admin.ai') }}" class="underline" wire:navigate>{{ __('AI úlohy') }}</a> · <a href="{{ route('admin.usage', ['household' => $this->household->id]) }}" class="underline" wire:navigate>{{ __('Ledger použití') }}</a></flux:text>
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-3" data-test="compensation-form">
            <form wire:submit="grantUses" class="space-y-3">
                <flux:heading size="lg" class="font-display">{{ __('Kompenzačné použitia') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Samostatný grant s dôvodom v audite. Nemení Stripe ani existujúce zostatky.') }}</flux:text>
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model="comp_kind" :label="__('Druh')">
                        @foreach (UsageKind::cases() as $kind)
                            <flux:select.option :value="$kind->value">{{ $kind->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="comp_quantity" type="number" min="1" max="1000" :label="__('Počet')" />
                    <flux:input wire:model="comp_expires" type="date" :label="__('Platí do')" />
                    <flux:input wire:model="comp_key" :label="__('Kľúč (tiket)')" />
                </div>
                <flux:textarea wire:model="comp_reason" rows="2" :label="__('Dôvod')" :placeholder="__('napr. výpadok AI 12. 3., tiket #42')" />
                <flux:button type="submit" variant="primary" size="sm">{{ __('Vystaviť kompenzáciu') }}</flux:button>
            </form>
        </flux:card>

        <flux:card class="space-y-3" data-test="plus-grant-form">
            <form wire:submit="grantPlus" class="space-y-3">
                <flux:heading size="lg" class="font-display">{{ __('Časovo obmedzený Plus') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Plus funkcie a mesačné použitia plánu na dané obdobie bez platby. Nevytvára objednávku ani doklad.') }}</flux:text>
                <flux:select wire:model="plus_plan" :label="__('Plán')">
                    @foreach ($this->plans as $plan)
                        <flux:select.option :value="$plan->code">{{ $plan->name }} (v{{ $plan->version }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="plus_from" type="date" :label="__('Od')" />
                <flux:input wire:model="plus_to" type="date" :label="__('Do (exkluzívne)')" />
                <flux:textarea wire:model="plus_reason" rows="2" :label="__('Dôvod')" />
                <flux:button type="submit" variant="primary" size="sm">{{ __('Udeliť Plus') }}</flux:button>
            </form>
        </flux:card>

        <flux:card class="space-y-3" data-test="block-form">
            <flux:heading size="lg" class="font-display">{{ $this->household->isBlocked() ? __('Blokovanie aktívne') : __('Blokovanie zneužitia') }}</flux:heading>
            @if ($this->household->isBlocked())
                <flux:callout variant="danger" icon="no-symbol" class="text-sm">
                    <flux:callout.text>{{ __('Od :date: :reason', ['date' => $this->household->blocked_at->setTimezone($tz)->format('d.m.Y H:i'), 'reason' => $this->household->blocked_reason]) }}</flux:callout.text>
                </flux:callout>
            @else
                <flux:text class="text-xs">{{ __('Zastaví nové AI úlohy a nákupy. Recepty, účty ani zaplatené nároky sa nemažú.') }}</flux:text>
            @endif
            <flux:textarea wire:model="block_reason" rows="2" :label="__('Dôvod')" />
            @if ($this->household->isBlocked())
                <flux:button size="sm" wire:click="unblock" data-test="unblock">{{ __('Zrušiť blokovanie') }}</flux:button>
            @else
                <flux:button size="sm" variant="danger" wire:click="block" wire:confirm="{{ __('Blokovať domácnosť? Nové AI úlohy a nákupy budú odmietnuté.') }}" data-test="block">{{ __('Blokovať') }}</flux:button>
            @endif
        </flux:card>
    </div>

    <flux:card class="space-y-3 overflow-x-auto" data-test="entitlements">
        <flux:heading size="lg" class="font-display">{{ __('Zaplatené obdobia') }}</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">{{ __('Plán') }}</th><th class="py-1 pe-2">{{ __('Od') }}</th><th class="py-1 pe-2">{{ __('Do') }}</th><th class="py-1 pe-2">{{ __('Zdroj') }}</th><th class="py-1 pe-2">{{ __('Stav') }}</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->entitlements as $e)
                    <tr wire:key="ent-{{ $e->id }}">
                        <td class="py-1.5 pe-2">{{ $e->planVersion->name }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $e->starts_at->setTimezone($tz)->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $e->ends_at->setTimezone($tz)->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 pe-2 text-xs">
                            @if ($e->order_id)<a href="{{ route('admin.orders.show', $e->order_id) }}" class="underline" wire:navigate>{{ __('objednávka #:id', ['id' => $e->order_id]) }}</a>@else {{ __('kompenzácia') }} @endif
                            @if ($e->stripe_invoice_id) · <a href="{{ StripeDashboard::invoice($e->stripe_invoice_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ __('faktúra') }}</a>@endif
                        </td>
                        <td class="py-1.5 pe-2">
                            @if ($e->revoked_at)<flux:badge color="red" size="sm">{{ __('odobrané') }}</flux:badge><div class="text-xs text-zinc-500">{{ $e->revoke_reason }}</div>
                            @elseif ($e->isActiveAt(now()))<flux:badge color="green" size="sm">{{ __('aktívne') }}</flux:badge>
                            @elseif ($e->starts_at > now())<flux:badge color="amber" size="sm">{{ __('budúce') }}</flux:badge>
                            @else<flux:badge color="zinc" size="sm">{{ __('skončené') }}</flux:badge>@endif
                        </td>
                        <td class="py-1.5 text-right">
                            @if ($e->order_id === null && $e->revoked_at === null && $e->ends_at > now())
                                <flux:button size="xs" variant="ghost" wire:click="revokePlus({{ $e->id }})" wire:confirm="{{ __('Ukončiť kompenzačné Plus obdobie teraz?') }}">{{ __('Ukončiť') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-zinc-500">{{ __('Žiadne zaplatené obdobia.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    <flux:card class="space-y-3 overflow-x-auto" data-test="grants">
        <flux:heading size="lg" class="font-display">{{ __('Granty použití') }}</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">#</th><th class="py-1 pe-2">{{ __('Druh') }}</th><th class="py-1 pe-2">{{ __('Zdroj') }}</th><th class="py-1 pe-2 text-right">{{ __('Množstvo') }}</th><th class="py-1 pe-2 text-right">{{ __('Rezerv.') }}</th><th class="py-1 pe-2 text-right">{{ __('Spotreb.') }}</th><th class="py-1 pe-2 text-right">{{ __('Odobr.') }}</th><th class="py-1 pe-2 text-right">{{ __('Voľné') }}</th><th class="py-1 pe-2">{{ __('Platnosť') }}</th><th class="py-1">{{ __('Poznámka') }}</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->grants as $g)
                    <tr wire:key="grant-{{ $g->id }}" class="{{ $g->isValidAt(now()) ? '' : 'text-zinc-400' }}">
                        <td class="py-1.5 pe-2">{{ $g->id }}</td>
                        <td class="py-1.5 pe-2">{{ $g->kind->label() }}</td>
                        <td class="py-1.5 pe-2">{{ $g->source->label() }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->reserved_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->consumed_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right tabular-nums">{{ $g->revoked_quantity }}</td>
                        <td class="py-1.5 pe-2 text-right font-medium tabular-nums">{{ $g->available() }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap text-xs">{{ $g->valid_from->setTimezone($tz)->format('d.m.Y') }} – {{ $g->expires_at?->setTimezone($tz)->format('d.m.Y') ?? __('bez expirácie') }}</td>
                        <td class="py-1.5 max-w-xs truncate text-xs" title="{{ $g->source_key }}">{{ $g->note }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-2 text-zinc-500">{{ __('Žiadne granty.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3 overflow-x-auto" data-test="orders">
            <flux:heading size="lg" class="font-display">{{ __('Objednávky') }}</flux:heading>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->orders as $o)
                        <tr wire:key="order-{{ $o->id }}">
                            <td class="py-1.5 pe-2"><a href="{{ route('admin.orders.show', $o) }}" class="underline" wire:navigate>#{{ $o->id }}</a></td>
                            <td class="py-1.5 pe-2 whitespace-nowrap">{{ $o->created_at->setTimezone($tz)->format('d.m.Y') }}</td>
                            <td class="py-1.5 pe-2">{{ $o->productName() }}</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($o->amount_cents, $o->currency) }}</td>
                            <td class="py-1.5 text-right"><flux:badge size="sm" :color="$o->status->badgeColor()">{{ $o->status->label() }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-zinc-500">{{ __('Žiadne objednávky.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>

        <flux:card class="space-y-3 overflow-x-auto" data-test="refunds">
            <flux:heading size="lg" class="font-display">{{ __('Refundácie a spory') }}</flux:heading>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                    @forelse ($this->refunds as $r)
                        <tr wire:key="refund-{{ $r->id }}">
                            <td class="py-1.5 pe-2"><a href="{{ route('admin.orders.show', $r->order_id) }}" class="underline" wire:navigate>#{{ $r->id }}</a></td>
                            <td class="py-1.5 pe-2">{{ $r->kind->label() }}</td>
                            <td class="py-1.5 pe-2 text-right tabular-nums">{{ Catalog::formatCents($r->amount_cents, $r->currency) }}</td>
                            <td class="py-1.5 text-right"><flux:badge size="sm" :color="$r->status->badgeColor()">{{ $r->status->label() }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-zinc-500">{{ __('Žiadne refundácie.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </flux:card>
    </div>
</div>
