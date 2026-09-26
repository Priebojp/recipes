<?php

use App\Enums\StripeEventState;
use App\Models\StripeEvent;
use App\Services\Admin\AdminAuditor;
use App\Services\Billing\StripeEventProcessor;
use App\Support\StripeDashboard;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Webhook inbox: what Stripe sent, whether it was processed, and a manual retry for failed or stuck events.
 * Processing is idempotent, so a retry can never grant anything twice.
 */
new #[Layout('layouts::admin')] #[Title('Stripe udalosti')] class extends Component {
    use WithPagination;

    #[Url]
    public string $state = '';

    #[Url]
    public string $search = '';

    public ?int $expanded = null;

    public function updated(string $property): void
    {
        if ($property === 'state' && $this->state !== '' && StripeEventState::tryFrom($this->state) === null) {
            $this->state = '';
        }
        $this->resetPage();
    }

    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return StripeEvent::query()
            ->when($this->state !== '', fn (Builder $q) => $q->where('state', $this->state))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('event_id', 'like', "%{$term}%")->orWhere('object_id', 'like', "%{$term}%")->orWhere('type', 'like', "%{$term}%")))
            ->latest('id')
            ->paginate(50);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return StripeEvent::query()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n)->all();
    }

    public function retry(int $id, StripeEventProcessor $processor, AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $event = StripeEvent::query()->findOrFail($id);
        if (! in_array($event->state, [StripeEventState::Failed, StripeEventState::Received], true)) {
            return;
        }

        $before = $event->only(['state', 'attempts', 'last_error']);
        $processor->process($event);
        $event->refresh();
        $audit->record('billing.stripe_event.retried', $event, $before, $event->only(['state', 'attempts', 'last_error']), 'Ručné opakovanie z administrácie');

        unset($this->events, $this->counts);
        Flux::toast(
            variant: $event->state === StripeEventState::Failed ? 'danger' : 'success',
            text: "Udalosť {$event->event_id}: ".$event->state->label().($event->last_error ? ' – '.$event->last_error : '.'),
        );
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Stripe udalosti" subtitle="Inbox webhookov. Každá udalosť sa najprv uloží, potom spracuje vo fronte; zlyhané sa opakujú denne (max. 5×) alebo ručne tu." />

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="evt_…, objekt alebo typ" clearable class="max-w-md" />
        <flux:select wire:model.live="state" class="w-56">
            <flux:select.option value="">všetky stavy</flux:select.option>
            @foreach (StripeEventState::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }} ({{ $this->counts[$case->value] ?? 0 }})</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">Prijatá</th><th class="py-1 pe-2">Typ</th><th class="py-1 pe-2">Udalosť</th><th class="py-1 pe-2">Objekt</th><th class="py-1 pe-2">Stav</th><th class="py-1 pe-2 text-right">Pokusy</th><th class="py-1 pe-2">Chyba</th><th class="py-1"></th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->events as $event)
                    <tr wire:key="event-{{ $event->id }}" class="align-top">
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $event->created_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i:s') }}</td>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $event->type }}</td>
                        <td class="py-1.5 pe-2 text-xs"><a href="{{ StripeDashboard::event($event->event_id) }}" class="underline" target="_blank" rel="noopener noreferrer">{{ $event->event_id }}</a>@if ($event->livemode) <flux:badge size="sm" color="red">live</flux:badge>@endif</td>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $event->object_id }}</td>
                        <td class="py-1.5 pe-2"><flux:badge size="sm" :color="$event->state->badgeColor()">{{ $event->state->label() }}</flux:badge></td>
                        <td class="py-1.5 pe-2 text-right">{{ $event->attempts }}</td>
                        <td class="py-1.5 pe-2 max-w-xs text-xs text-red-600">{{ $event->last_error }}</td>
                        <td class="py-1.5 whitespace-nowrap text-right">
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $event->id }})">{{ $expanded === $event->id ? 'skryť' : 'payload' }}</flux:button>
                            @if (in_array($event->state, [StripeEventState::Failed, StripeEventState::Received], true))
                                <flux:button size="xs" icon="arrow-path" wire:click="retry({{ $event->id }})" data-test="retry-{{ $event->id }}">Spracovať znova</flux:button>
                            @endif
                        </td>
                    </tr>
                    @if ($expanded === $event->id)
                        <tr wire:key="payload-{{ $event->id }}"><td colspan="8" class="bg-zinc-50 p-2 dark:bg-zinc-800/50"><pre class="max-h-80 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></td></tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="py-3 text-zinc-500">Žiadne udalosti.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $this->events->links() }}
    </flux:card>
</div>
