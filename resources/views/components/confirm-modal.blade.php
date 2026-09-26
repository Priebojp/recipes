{{-- Confirmation dialog replacing browser confirm(). Open it with $flux.modal('<name>').show() or <flux:modal.trigger name="…">. --}}
@props(['name', 'title', 'text' => null, 'confirm' => 'Potvrdiť', 'action', 'variant' => 'danger', 'icon' => 'exclamation-triangle'])

<flux:modal :name="$name" class="min-w-[22rem] max-w-md">
    <div class="space-y-5">
        <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $variant === 'danger' ? 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300' : 'bg-accent/10 text-accent' }}">
                <flux:icon :name="$icon" class="size-5" />
            </span>
            <div class="space-y-1">
                <flux:heading size="lg">{{ $title }}</flux:heading>
                @if ($text)
                    <flux:text>{{ $text }}</flux:text>
                @endif
            </div>
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">Zrušiť</flux:button>
            </flux:modal.close>
            <flux:button :variant="$variant" wire:click="{{ $action }}" x-on:click="$flux.modal('{{ $name }}').close()" data-test="confirm-{{ $name }}">{{ $confirm }}</flux:button>
        </div>
    </div>
</flux:modal>
