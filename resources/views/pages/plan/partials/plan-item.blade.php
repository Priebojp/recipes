<div class="mb-1 flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/40 {{ $plan->status === App\Enums\PlanStatus::Cancelled ? 'opacity-50' : '' }} {{ $plan->status === App\Enums\PlanStatus::Cooked ? 'bg-green-50 dark:bg-green-900/20' : '' }}" wire:key="plan-{{ $plan->id }}" data-test="plan-{{ $plan->id }}">
    <x-recipe-cover :recipe="$plan->recipe" conversion="thumb" class="size-12 shrink-0 rounded-lg" />
    <div class="min-w-0 flex-1">
        <a href="{{ route('recipes.show', $plan->recipe) }}" wire:navigate class="block truncate text-sm font-medium">{{ $plan->recipe->title }}</a>
        <div class="flex flex-wrap items-center gap-1 text-xs text-zinc-500">
            @if ($plan->mode !== App\Enums\PlanMode::Date)<span>{{ $plan->termLabel() }}</span>@endif
            @if ($plan->meal_type)<span>{{ $plan->meal_type->label() }}</span>@endif
            @if ($plan->servings)<span>· {{ $plan->servings }} porc.</span>@endif
            <span class="flex -space-x-1">@foreach ($plan->people as $person)<x-person-avatar :person="$person" size="size-4" />@endforeach</span>
            @if ($plan->status === App\Enums\PlanStatus::Cooked)<flux:badge size="sm" color="green">Uvarené</flux:badge>@endif
            @if ($plan->status === App\Enums\PlanStatus::Cancelled)<flux:badge size="sm" color="zinc">Zrušené</flux:badge>@endif
        </div>
    </div>
    <flux:dropdown align="end">
        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="Akcie plánu" />
        <flux:menu>
            @if ($plan->status === App\Enums\PlanStatus::Planned)
                <flux:menu.item wire:click="cooked({{ $plan->id }})" icon="check">Uvarené</flux:menu.item>
                <flux:menu.item wire:click="move({{ $plan->id }})" icon="calendar-days">Presunúť / upraviť</flux:menu.item>
                <flux:menu.item wire:click="cancel({{ $plan->id }})" icon="x-mark">Nevaril som / odstrániť</flux:menu.item>
            @elseif ($plan->status === App\Enums\PlanStatus::Cancelled)
                <flux:menu.item wire:click="restore({{ $plan->id }})" icon="arrow-uturn-left">Vrátiť do plánu</flux:menu.item>
            @else
                <flux:menu.item wire:click="undoCooked({{ $plan->id }})" icon="arrow-uturn-left">Opraviť omyl (nebolo uvarené)</flux:menu.item>
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
