<?php

use App\Services\Admin\AdminAuditor;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\PlanStatus;
use App\Support\StripeDashboard;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Cashier\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cashier subscriptions as synced from Stripe, with the household's paid-through date from our entitlements.
 * Actions go through the gateway and are audited with a reason.
 */
new #[Layout('layouts::admin')] #[Title('Predplatné')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public string $reason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function subscriptions(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Subscription::query()
            ->with(['owner.household.owner:id,email'])
            ->when($this->status === 'canceling', fn (Builder $q) => $q->whereNotNull('ends_at')->where('ends_at', '>', now()))
            ->when($this->status !== '' && $this->status !== 'canceling', fn (Builder $q) => $q->where('stripe_status', $this->status))
            ->when($term !== '', function (Builder $q) use ($term) {
                $q->where(function (Builder $w) use ($term) {
                    $w->where('stripe_id', 'like', "%{$term}%")
                        ->orWhereHas('owner', function (Builder $account) use ($term) {
                            $account->where('stripe_id', 'like', "%{$term}%")
                                ->orWhereHas('household', function (Builder $household) use ($term) {
                                    $household->where('name', 'like', "%{$term}%")
                                        ->orWhereHas('owner', fn (Builder $user) => $user->where('email', 'like', "%{$term}%"));
                                    if (ctype_digit($term)) {
                                        $household->orWhere('id', (int) $term);
                                    }
                                });
                        });
                });
            })
            ->latest('id')
            ->paginate(25);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return Subscription::query()->selectRaw('stripe_status, count(*) as n')->groupBy('stripe_status')->pluck('n', 'stripe_status')->map(fn ($n) => (int) $n)->all();
    }

    public function sync(int $id, StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $subscription = Subscription::query()->findOrFail($id);
        $before = $subscription->only(['stripe_status', 'ends_at']);

        $gateway->syncSubscription($subscription);
        $audit->record('billing.subscription.synced', $subscription, $before, $subscription->fresh()->only(['stripe_status', 'ends_at']), 'Ručná synchronizácia zo Stripe');

        unset($this->subscriptions, $this->counts);
        Flux::toast(text: "Predplatné {$subscription->stripe_id} synchronizované.");
    }

    public function cancelRenewal(int $id, StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $subscription = Subscription::query()->findOrFail($id);
        if ($subscription->canceled() || $subscription->ended()) {
            return;
        }

        $gateway->cancelRenewal($subscription);
        $audit->record('billing.subscription.renewal_canceled', $subscription, [], ['stripe_id' => $subscription->stripe_id], $this->reason);

        $this->reset('reason');
        unset($this->subscriptions, $this->counts);
        Flux::toast(text: 'Obnovovanie zrušené; zaplatené obdobie sa neskracuje.');
    }

    public function resumeRenewal(int $id, StripeGateway $gateway, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $subscription = Subscription::query()->findOrFail($id);
        if (! $subscription->onGracePeriod()) {
            return;
        }

        $gateway->resumeRenewal($subscription);
        $audit->record('billing.subscription.renewal_resumed', $subscription, [], ['stripe_id' => $subscription->stripe_id], $this->reason);

        $this->reset('reason');
        unset($this->subscriptions, $this->counts);
        Flux::toast(text: 'Obnovovanie znova zapnuté.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Predplatné" subtitle="Cashier záznamy synchronizované zo Stripe. Zaplatené obdobia určujú nároky; tu sa nič neaktivuje ručne." />

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Stripe ID, zákazník, domácnosť, e-mail" clearable class="max-w-md" />
        <flux:select wire:model.live="status" class="w-52">
            <flux:select.option value="">všetky stavy</flux:select.option>
            @foreach ($this->counts as $state => $n)
                <flux:select.option :value="$state">{{ $state }} ({{ $n }})</flux:select.option>
            @endforeach
            <flux:select.option value="canceling">bez obnovy (cancel at period end)</flux:select.option>
        </flux:select>
        <flux:input wire:model="reason" placeholder="Dôvod pre zmenu obnovovania" class="max-w-xs" />
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Stripe</th><th class="py-1 pe-2">Stav</th><th class="py-1 pe-2">Zaplatené do</th><th class="py-1 pe-2">Končí</th><th class="py-1">Akcie</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->subscriptions as $sub)
                    @php($household = $sub->owner?->household)
                    @php($paidThrough = $household ? app(PlanStatus::class)->paidThrough($household) : null)
                    <tr wire:key="sub-{{ $sub->id }}" class="align-top">
                        <td class="py-1.5 pe-2">
                            @if ($household)
                                <a href="{{ route('admin.households.show', $household) }}" class="underline" wire:navigate>#{{ $household->id }} {{ $household->name }}</a>
                                <div class="text-xs text-zinc-500">{{ $household->owner?->email }}</div>
                            @else – @endif
                        </td>
                        <td class="py-1.5 pe-2 text-xs">
                            <a href="{{ StripeDashboard::subscription($sub->stripe_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $sub->stripe_id }}</a>
                            <div class="font-mono text-zinc-500">{{ $sub->stripe_price }}</div>
                        </td>
                        <td class="py-1.5 pe-2"><flux:badge size="sm" :color="match ($sub->stripe_status) { 'active', 'trialing' => 'green', 'past_due', 'unpaid', 'incomplete' => 'amber', default => 'zinc' }">{{ $sub->stripe_status }}</flux:badge></td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $paidThrough?->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') ?? '–' }}</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $sub->ends_at?->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') ?? '–' }}</td>
                        <td class="py-1.5">
                            <div class="flex flex-wrap gap-1">
                                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="sync({{ $sub->id }})">Sync</flux:button>
                                @unless ($sub->ended())
                                    @if ($sub->onGracePeriod())
                                        <flux:button size="xs" wire:click="resumeRenewal({{ $sub->id }})">Obnovovať znova</flux:button>
                                    @elseif (! $sub->canceled())
                                        <flux:button size="xs" variant="danger" wire:click="cancelRenewal({{ $sub->id }})" wire:confirm="Zrušiť obnovovanie tohto predplatného?">Zrušiť obnovovanie</flux:button>
                                    @endif
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-zinc-500">Žiadne predplatné.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->subscriptions->links() }}
    </flux:card>
</div>
