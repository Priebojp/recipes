<?php

use App\Enums\PlusFeature;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\PlanningCalendar;
use App\Services\Plus\PlusAccess;
use App\Services\Plus\ShoppingListBuilder;
use App\Support\CurrentHousehold;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Nákupný zoznam')] class extends Component {
    #[Url]
    public ?string $week = null;

    public string $newName = '';

    public string $newAmount = '';

    public string $newUnit = '';

    public string $error = '';

    public function mount(): void
    {
        $calendar = $this->calendar;
        if ($this->week === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) !== 1) {
            $this->week = $calendar->thisWeekStart()->toDateString();
        } else {
            $this->week = $calendar->weekStartOf($calendar->date($this->week))->toDateString();
        }
    }

    #[Computed]
    public function household(): Household
    {
        return app(CurrentHousehold::class)->get();
    }

    #[Computed]
    public function calendar(): PlanningCalendar
    {
        return new PlanningCalendar($this->household->timezone);
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->calendar->date($this->week);
    }

    #[Computed]
    public function isPlus(): bool
    {
        return app(PlusAccess::class)->allows($this->household, PlusFeature::ShoppingList);
    }

    #[Computed]
    public function list(): ?ShoppingList
    {
        return ShoppingList::query()->where('household_id', $this->household->id)->where('week_start_date', $this->week)->with('items')->first();
    }

    #[Computed]
    public function planCount(): int
    {
        return app(ShoppingListBuilder::class)->plansForWeek($this->household, $this->weekStart)->count();
    }

    #[Computed]
    public function asText(): string
    {
        $list = $this->list;
        if ($list === null) {
            return '';
        }

        return $list->items->reject(fn (ShoppingListItem $i) => $i->isChecked())->map(function (ShoppingListItem $i) {
            $parts = array_filter([$i->amountLabel(), ...($i->text_amounts ?? [])]);

            return '- '.$i->name.($parts !== [] ? ' – '.implode(', ', $parts) : '');
        })->implode("\n");
    }

    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart->addWeeks($weeks)->toDateString();
        $this->error = '';
        unset($this->weekStart, $this->list, $this->planCount, $this->asText);
    }

    public function generate(ShoppingListBuilder $builder): void
    {
        app(PlusAccess::class)->assert($this->household, PlusFeature::ShoppingList);
        $this->authorize('view', $this->household);

        $builder->generate($this->household, $this->weekStart, auth()->user());
        $this->error = '';
        unset($this->list, $this->asText);
    }

    public function toggle(int $itemId, ShoppingListBuilder $builder): void
    {
        $builder->toggle($this->item($itemId));
        unset($this->list, $this->asText);
    }

    public function addItem(ShoppingListBuilder $builder): void
    {
        $list = $this->list;
        if ($list === null) {
            return;
        }
        $this->authorize('update', $list);
        $this->validate(['newName' => ['required', 'string', 'max:200'], 'newAmount' => ['nullable', 'string', 'max:100'], 'newUnit' => ['nullable', 'string', 'max:50']], ['newName.required' => __('Zadaj názov položky.')]);

        try {
            $builder->addManual($list, $this->newName, $this->newAmount ?: null, $this->newUnit ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('newName', 'newAmount', 'newUnit', 'error');
        unset($this->list, $this->asText);
    }

    public function removeItem(int $itemId, ShoppingListBuilder $builder): void
    {
        try {
            $builder->remove($this->item($itemId));
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }
        unset($this->list, $this->asText);
    }

    private function item(int $itemId): ShoppingListItem
    {
        $item = ShoppingListItem::query()
            ->whereHas('shoppingList', fn ($q) => $q->where('household_id', $this->household->id))
            ->with('shoppingList')
            ->findOrFail($itemId);
        $this->authorize('update', $item->shoppingList);

        return $item;
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="__('Nákupný zoznam')" :back="route('plan.index', ['week' => $week])" :subtitle="__('Suroviny naplánovaných jedál v týždni, prepočítané na porcie.')" />

    <div class="flex items-center justify-between">
        <flux:button wire:click="shiftWeek(-1)" variant="ghost" icon="chevron-left" size="sm" :aria-label="__('Predchádzajúci týždeň')" />
        <flux:heading size="lg" class="font-display">{{ __('Týždeň :from – :to', ['from' => $this->weekStart->format('j. n.'), 'to' => $this->weekStart->addDays(6)->format('j. n. Y')]) }}</flux:heading>
        <flux:button wire:click="shiftWeek(1)" variant="ghost" icon="chevron-right" size="sm" :aria-label="__('Nasledujúci týždeň')" />
    </div>

    @php($list = $this->list)

    @if (! $this->isPlus && $list === null)
        <x-plus-gate :feature="App\Enums\PlusFeature::ShoppingList" :household="$this->household" />
    @else
        <flux:card size="sm" class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm">
                @if ($list?->generated_at)
                    {{ __('Zostavené :date z :meals.', ['date' => $list->generated_at->timezone($this->household->timezone)->translatedFormat('j. n. H:i'), 'meals' => trans_choice('{1} :count naplánovaného jedla|[2,*] :count naplánovaných jedál', $list->plan_count)]) }}
                    @if ($this->planCount !== $list->plan_count)
                        <span class="text-amber-700 dark:text-amber-300">{{ __('Plán sa odvtedy zmenil (:count).', ['count' => $this->planCount]) }}</span>
                    @endif
                @else
                    {{ __('V tomto týždni je :meals.', ['meals' => trans_choice('{0} nič naplánované|{1} :count naplánované jedlo|[2,4] :count naplánované jedlá|[5,*] :count naplánovaných jedál', $this->planCount)]) }}
                @endif
            </div>
            @if ($this->isPlus)
                <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="generate" :disabled="$this->planCount === 0 && $list === null" data-test="generate-list">{{ $list ? __('Zostaviť znova z plánu') : __('Zostaviť z plánu') }}</flux:button>
            @else
                <flux:text class="text-xs">{{ __('Nové zostavenie vyžaduje Plus; existujúci zoznam zostáva.') }}</flux:text>
            @endif
        </flux:card>

        @if ($error)
            <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
        @endif

        @if ($list)
            @php($open = $list->items->reject->isChecked())
            @php($done = $list->items->filter->isChecked())

            <section class="space-y-1" data-test="shopping-items">
                @forelse ($open as $item)
                    @include('pages.plan.partials.shopping-item', ['item' => $item])
                @empty
                    <flux:card variant="soft" size="sm" class="text-center"><flux:text>{{ $done->isEmpty() ? __('Zoznam je prázdny. Naplánuj jedlá alebo pridaj vlastnú položku.') : __('Všetko nakúpené.') }}</flux:text></flux:card>
                @endforelse
            </section>

            <form wire:submit="addItem" class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 p-3 sm:flex-row sm:items-end dark:border-zinc-600">
                <flux:input wire:model="newName" :label="__('Vlastná položka')" :placeholder="__('napr. chlieb')" class="flex-1" />
                <flux:input wire:model="newAmount" :label="__('Množstvo')" placeholder="1" class="sm:w-24" />
                <flux:input wire:model="newUnit" :label="__('Jednotka')" :placeholder="__('ks')" class="sm:w-24" />
                <flux:button type="submit" size="sm" icon="plus" data-test="add-item">{{ __('Pridať') }}</flux:button>
            </form>

            @if ($done->isNotEmpty())
                <section class="space-y-1">
                    <flux:heading size="sm" class="text-zinc-500">{{ __('Nakúpené (:count)', ['count' => $done->count()]) }}</flux:heading>
                    @foreach ($done as $item)
                        @include('pages.plan.partials.shopping-item', ['item' => $item])
                    @endforeach
                </section>
            @endif

            <div x-data="{ copied: false }" class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText($wire.asText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">{{ __('Kopírovať ako text') }}</flux:button>
                <span x-show="copied" x-cloak class="text-xs text-zinc-500">{{ __('Skopírované') }}</span>
            </div>
            <flux:text class="text-xs">{{ __('Zlučujú sa iba položky s rovnakým názvom a jednotkou; „g“ a „kg“ alebo „podľa chuti“ zostávajú samostatne s názvom receptu. Recept bez základných porcií sa neprepočítava.') }}</flux:text>
        @endif
    @endif
</div>
