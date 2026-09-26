<div class="flex items-start gap-3 rounded-xl p-2 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/40 {{ $item->isChecked() ? 'opacity-60' : '' }}" wire:key="item-{{ $item->id }}" data-test="item-{{ $item->id }}">
    <button type="button" wire:click="toggle({{ $item->id }})" class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md border {{ $item->isChecked() ? 'border-accent bg-accent text-accent-foreground' : 'border-zinc-300 dark:border-zinc-600' }}" aria-label="{{ $item->isChecked() ? 'Vrátiť medzi nenakúpené' : 'Označiť ako nakúpené' }}" data-test="toggle-{{ $item->id }}">
        @if ($item->isChecked())<flux:icon name="check" class="size-4" />@endif
    </button>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span class="font-medium {{ $item->isChecked() ? 'line-through' : '' }}">{{ $item->name }}</span>
            @if ($item->amountLabel() !== '')<span class="font-semibold">{{ $item->amountLabel() }}</span>@endif
            @if ($item->manual)<flux:badge size="sm" color="zinc">vlastné</flux:badge>@endif
        </div>
        @if ($item->text_amounts)
            <div class="text-xs text-zinc-500">{{ implode(' · ', $item->text_amounts) }}</div>
        @endif
        @if ($item->sources)
            <div class="truncate text-xs text-zinc-400">{{ collect($item->sources)->pluck('title')->unique()->implode(', ') }}</div>
        @endif
    </div>
    @if ($item->manual)
        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" aria-label="Odstrániť položku" />
    @endif
</div>
