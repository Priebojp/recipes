<?php

use App\Enums\PlanMode;
use App\Enums\PlanStatus;
use App\Enums\PlusFeature;
use App\Models\MealPlan;
use App\Services\MealPlanningService;
use App\Services\PlanningCalendar;
use App\Services\Plus\PlusAccess;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Plán')] class extends Component {
    #[Url]
    public ?string $week = null;

    public bool $showCancelled = false;

    #[Computed]
    public function calendar(): PlanningCalendar
    {
        return new PlanningCalendar(app(CurrentHousehold::class)->timezone());
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->week ? $this->calendar->weekStartOf($this->calendar->date($this->week)) : $this->calendar->thisWeekStart();
    }

    #[Computed]
    public function isPlus(): bool
    {
        return app(PlusAccess::class)->allows(app(CurrentHousehold::class)->get(), PlusFeature::WeeklyMenu);
    }

    #[Computed]
    public function plans()
    {
        $start = $this->weekStart;
        $end = $start->addDays(6);

        return MealPlan::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->whereIn('status', $this->showCancelled ? [PlanStatus::Planned, PlanStatus::Cooked, PlanStatus::Cancelled] : [PlanStatus::Planned, PlanStatus::Cooked])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere('week_start_date', $start->toDateString());
            })
            ->with(['recipe.cover', 'people'])
            ->orderBy('scheduled_date')->orderBy('meal_type')
            ->get();
    }

    #[Computed]
    public function overdue()
    {
        return MealPlan::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->where('status', PlanStatus::Planned)
            ->where(function ($q) {
                $q->where('scheduled_date', '<', $this->calendar->today()->toDateString())
                    ->orWhere('week_start_date', '<', $this->calendar->thisWeekStart()->toDateString());
            })
            ->with(['recipe.cover', 'people'])
            ->orderBy('scheduled_date')
            ->get();
    }

    #[Computed]
    public function someday()
    {
        return MealPlan::query()
            ->where('household_id', app(CurrentHousehold::class)->id())
            ->where('status', PlanStatus::Planned)
            ->where('mode', PlanMode::Someday)
            ->with(['recipe.cover', 'people'])
            ->latest()
            ->get();
    }

    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart->addWeeks($weeks)->toDateString();
        $this->refresh();
    }

    public function cooked(int $planId): void
    {
        $plan = $this->find($planId);
        $this->dispatch('open-cooked-panel', recipeId: $plan->recipe_id, planId: $plan->id);
    }

    public function move(int $planId): void
    {
        $plan = $this->find($planId);
        $term = match ($plan->mode) {
            PlanMode::Date => 'date',
            PlanMode::Week => $plan->week_start_date?->equalTo($this->calendar->thisWeekStart()) ? 'this_week' : 'next_week',
            PlanMode::Someday => 'unknown',
        };
        $this->dispatch('open-plan-panel', recipeId: $plan->recipe_id, defaults: [
            'person_ids' => $plan->people->pluck('id')->all(),
            'meal_type' => $plan->meal_type?->value ?? 'any',
            'term' => $term,
            'date' => $plan->scheduled_date?->toDateString(),
            'servings' => $plan->servings,
        ], planId: $plan->id);
    }

    public function cancel(int $planId, MealPlanningService $planning): void
    {
        $planning->cancel($this->find($planId));
        $this->refresh();
    }

    public function restore(int $planId, MealPlanningService $planning): void
    {
        $planning->restore($this->find($planId));
        $this->refresh();
    }

    public function undoCooked(int $planId, MealPlanningService $planning): void
    {
        $planning->undoCooked($this->find($planId));
        $this->refresh();
    }

    #[On('plan-saved')]
    #[On('cooked-saved')]
    public function refresh(): void
    {
        unset($this->plans, $this->overdue, $this->someday, $this->weekStart);
    }

    private function find(int $planId): MealPlan
    {
        $plan = MealPlan::query()->where('household_id', app(CurrentHousehold::class)->id())->with('people')->findOrFail($planId);
        $this->authorize('update', $plan);

        return $plan;
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-page-header title="Plán">
        <flux:button :href="route('plan.history')" wire:navigate variant="ghost" icon="clock" size="sm">História</flux:button>
    </x-page-header>

    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('plan.propose', ['week' => $this->weekStart->toDateString()]) }}" wire:navigate class="flex items-center gap-3 rounded-2xl border border-zinc-200/80 bg-white p-3 text-sm font-medium transition hover:border-accent/50 dark:border-zinc-700 dark:bg-zinc-800" data-test="propose-link">
            <flux:icon name="sparkles" class="size-5 shrink-0 text-accent" />
            <span class="min-w-0 flex-1">Navrhnúť týždeň</span>
            @unless ($this->isPlus)<flux:badge size="sm" color="orange">Plus</flux:badge>@endunless
        </a>
        <a href="{{ route('plan.shopping', ['week' => $this->weekStart->toDateString()]) }}" wire:navigate class="flex items-center gap-3 rounded-2xl border border-zinc-200/80 bg-white p-3 text-sm font-medium transition hover:border-accent/50 dark:border-zinc-700 dark:bg-zinc-800" data-test="shopping-link">
            <flux:icon name="shopping-cart" class="size-5 shrink-0 text-accent" />
            <span class="min-w-0 flex-1">Nákupný zoznam</span>
            @unless ($this->isPlus)<flux:badge size="sm" color="orange">Plus</flux:badge>@endunless
        </a>
    </div>

    @if ($this->overdue->isNotEmpty())
        <section class="space-y-2">
            <flux:heading size="lg" class="font-display">Nepotvrdené z minulosti</flux:heading>
            <flux:text class="text-sm">Deň v minulosti neznamená uvarené. Potvrď, presuň alebo zruš.</flux:text>
            @foreach ($this->overdue as $plan)
                @include('pages.plan.partials.plan-item', ['plan' => $plan])
            @endforeach
        </section>
    @endif

    <section class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:button wire:click="shiftWeek(-1)" variant="ghost" icon="chevron-left" size="sm" aria-label="Predchádzajúci týždeň" />
            <flux:heading size="lg" class="font-display">Týždeň {{ $this->weekStart->format('j. n.') }} – {{ $this->weekStart->addDays(6)->format('j. n. Y') }}</flux:heading>
            <flux:button wire:click="shiftWeek(1)" variant="ghost" icon="chevron-right" size="sm" aria-label="Nasledujúci týždeň" />
        </div>

        @php($today = $this->calendar->today())
        @for ($i = 0; $i < 7; $i++)
            @php($day = $this->weekStart->addDays($i))
            @php($items = $this->plans->filter(fn ($p) => $p->scheduled_date?->equalTo($day)))
            <flux:card size="sm" class="!p-2 {{ $day->equalTo($today) ? 'border-accent ring-1 ring-accent' : '' }}">
                <div class="mb-1 flex items-center justify-between px-1 text-sm font-semibold">
                    <span class="capitalize">{{ $day->translatedFormat('l j. n.') }}</span>
                    @if ($day->equalTo($today))<flux:badge size="sm" color="orange" variant="pill">Dnes</flux:badge>@endif
                </div>
                @forelse ($items as $plan)
                    @include('pages.plan.partials.plan-item', ['plan' => $plan])
                @empty
                    <div class="px-1 text-xs text-zinc-400">–</div>
                @endforelse
            </flux:card>
        @endfor

        @php($weekItems = $this->plans->filter(fn ($p) => $p->mode === PlanMode::Week))
        <div class="rounded-xl border border-dashed border-zinc-300 p-2 dark:border-zinc-600">
            <div class="mb-1 px-1 text-sm font-semibold">Tento týždeň – bez dňa</div>
            @forelse ($weekItems as $plan)
                @include('pages.plan.partials.plan-item', ['plan' => $plan])
            @empty
                <div class="px-1 text-xs text-zinc-400">Nič bez určeného dňa.</div>
            @endforelse
        </div>

        <flux:checkbox wire:model.live="showCancelled" label="Zobraziť aj zrušené" />
    </section>

    @if ($this->someday->isNotEmpty())
        <section class="space-y-2">
            <flux:heading size="lg" class="font-display">Niekedy</flux:heading>
            @foreach ($this->someday as $plan)
                @include('pages.plan.partials.plan-item', ['plan' => $plan])
            @endforeach
        </section>
    @endif

    <livewire:plan-panel />
    <livewire:cooked-panel />
</div>
