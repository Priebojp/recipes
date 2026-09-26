<?php

use App\Models\AiJob;
use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only overview of households: size, activity and AI spend this month. No recipes, no diner names.
 */
new #[Layout('layouts::admin')] #[Title('Domácnosti')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $plan = '';

    public function updated(string $property): void
    {
        if ($property === 'plan' && ! in_array($this->plan, ['', 'plus', 'free', 'blocked'], true)) {
            $this->plan = '';
        }
        $this->resetPage();
    }

    #[Computed]
    public function households(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Household::query()
            ->withCount(['memberships', 'recipes', 'people'])
            ->with('owner:id,name,email')
            ->when($term !== '', function (Builder $query) use ($term) {
                $query->where(function (Builder $q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhereHas('owner', fn (Builder $o) => $o->where('email', 'like', "%{$term}%"));
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            })
            ->when($this->plan === 'blocked', fn (Builder $query) => $query->whereNotNull('blocked_at'))
            ->when(in_array($this->plan, ['plus', 'free'], true), function (Builder $query) {
                $active = fn (Builder $q) => $q->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
                $this->plan === 'plus' ? $query->whereHas('paidEntitlements', $active) : $query->whereDoesntHave('paidEntitlements', $active);
            })
            ->latest('id')
            ->paginate(25);
    }

    /** @return array<int, PaidEntitlement> current paid period per listed household */
    #[Computed]
    public function plans(): array
    {
        $ids = collect($this->households->items())->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        return PaidEntitlement::query()
            ->whereIn('household_id', $ids)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with('planVersion:id,name,interval')
            ->orderByDesc('ends_at')
            ->get()
            ->unique('household_id')
            ->keyBy('household_id')
            ->all();
    }

    /** @return array<int, object{jobs: int, cost_micro: int}> */
    #[Computed]
    public function aiThisMonth(): array
    {
        $ids = collect($this->households->items())->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $start = CarbonImmutable::now(config('recipes.default_timezone'))->startOfMonth()->utc();

        return AiJob::query()
            ->whereIn('household_id', $ids)
            ->where('created_at', '>=', $start)
            ->selectRaw('household_id, count(*) as jobs, coalesce(sum(estimated_cost_micro_usd), 0) as cost_micro')
            ->groupBy('household_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->household_id => (object) ['jobs' => (int) $row->jobs, 'cost_micro' => (int) $row->cost_micro]])
            ->all();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Domácnosti" subtitle="Stav, plán a aktivita domácností. Detail otvára predplatné, objednávky, použitia, kompenzácie a blokovanie." />

    <div class="flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Hľadať podľa názvu, e-mailu vlastníka alebo ID" clearable class="max-w-md" />
        <flux:select wire:model.live="plan" class="w-44">
            <flux:select.option value="">všetky plány</flux:select.option>
            <flux:select.option value="plus">Plus</flux:select.option>
            <flux:select.option value="free">Free</flux:select.option>
            <flux:select.option value="blocked">blokované</flux:select.option>
        </flux:select>
    </div>

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="py-1 pe-2">ID</th><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Vlastník</th><th class="py-1 pe-2">Plán</th>
                    <th class="py-1 pe-2 text-right">Účty</th><th class="py-1 pe-2 text-right">Recepty</th>
                    <th class="py-1 pe-2 text-right">AI tento mesiac</th><th class="py-1">Vytvorená</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->households as $household)
                    @php($ai = $this->aiThisMonth[$household->id] ?? null)
                    @php($current = $this->plans[$household->id] ?? null)
                    <tr wire:key="household-{{ $household->id }}">
                        <td class="py-1.5 pe-2 text-zinc-500"><a href="{{ route('admin.households.show', $household) }}" class="underline" wire:navigate>#{{ $household->id }}</a></td>
                        <td class="py-1.5 pe-2 font-medium">
                            <a href="{{ route('admin.households.show', $household) }}" wire:navigate>{{ $household->name }}</a>
                            @if ($household->isBlocked())<flux:badge color="red" size="sm" class="ms-1">blokovaná</flux:badge>@endif
                            <div class="text-xs text-zinc-500">{{ $household->timezone }} · {{ $household->people_count }} stravníkov</div>
                        </td>
                        <td class="py-1.5 pe-2">{{ $household->owner?->email ?? '–' }}@unless ($household->owner?->email_verified_at)<span class="ms-1 text-xs text-amber-600">neoverený</span>@endunless</td>
                        <td class="py-1.5 pe-2 whitespace-nowrap">
                            @if ($current)
                                <flux:badge color="green" size="sm">Plus</flux:badge>
                                <div class="text-xs text-zinc-500">do {{ $current->ends_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}@if ($current->order_id === null) · kompenzácia @endif</div>
                            @else
                                <flux:badge color="zinc" size="sm">Free</flux:badge>
                            @endif
                        </td>
                        <td class="py-1.5 pe-2 text-right">{{ $household->memberships_count }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $household->recipes_count }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $ai ? $ai->jobs.' · '.Money::microUsd($ai->cost_micro) : '–' }}</td>
                        <td class="py-1.5 whitespace-nowrap">{{ $household->created_at?->setTimezone(config('recipes.default_timezone'))->format('d.m.Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-3 text-zinc-500">Žiadne domácnosti.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $this->households->links() }}
    </flux:card>
</div>
