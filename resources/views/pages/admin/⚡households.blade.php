<?php

use App\Models\AiJob;
use App\Models\Household;
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

    public function updatedSearch(): void
    {
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
            ->latest('id')
            ->paginate(25);
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
    <x-page-header title="Domácnosti" subtitle="Stav a aktivita domácností. Plán, obnova a blokovanie pribudnú s etapou Cashier." />

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Hľadať podľa názvu, e-mailu vlastníka alebo ID" clearable class="max-w-md" />

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="py-1 pe-2">ID</th><th class="py-1 pe-2">Domácnosť</th><th class="py-1 pe-2">Vlastník</th>
                    <th class="py-1 pe-2 text-right">Účty</th><th class="py-1 pe-2 text-right">Stravníci</th><th class="py-1 pe-2 text-right">Recepty</th>
                    <th class="py-1 pe-2 text-right">AI tento mesiac</th><th class="py-1">Vytvorená</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->households as $household)
                    @php($ai = $this->aiThisMonth[$household->id] ?? null)
                    <tr>
                        <td class="py-1.5 pe-2 text-zinc-500">#{{ $household->id }}</td>
                        <td class="py-1.5 pe-2 font-medium">{{ $household->name }}<div class="text-xs text-zinc-500">{{ $household->timezone }}</div></td>
                        <td class="py-1.5 pe-2">{{ $household->owner?->email ?? '–' }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $household->memberships_count }}</td>
                        <td class="py-1.5 pe-2 text-right">{{ $household->people_count }}</td>
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
