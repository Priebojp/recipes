<?php

use App\Models\AdminAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Append-only administrator audit trail: who, what, when, target, reason. Nothing here can be deleted from the UI.
 */
new #[Layout('layouts::admin')] #[Title('Audit')] class extends Component {
    use WithPagination;

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return AdminAudit::query()->with('actor:id,name,email')->latest('id')->paginate(50);
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Audit" subtitle="Kto, čo, kedy, cieľ a dôvod. Citlivé hodnoty sú pred uložením redigované; záznamy sa v rozhraní nemažú." />

    <flux:card class="space-y-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500">
                <tr><th class="py-1 pe-2">Čas</th><th class="py-1 pe-2">Kto</th><th class="py-1 pe-2">Akcia</th><th class="py-1 pe-2">Cieľ</th><th class="py-1 pe-2">Dôvod</th><th class="py-1">Zmeny</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->entries as $entry)
                    <tr class="align-top">
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $entry->created_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i:s') }}</td>
                        <td class="py-1.5 pe-2">{{ $entry->actor?->email ?? 'systém / CLI' }}</td>
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $entry->action }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ $entry->target_type }}@if ($entry->target_id) #{{ $entry->target_id }}@endif</td>
                        <td class="py-1.5 pe-2 max-w-xs text-xs">{{ $entry->reason }}</td>
                        <td class="py-1.5">
                            @if ($entry->changes)
                                <details>
                                    <summary class="cursor-pointer text-xs text-accent">zobraziť</summary>
                                    <pre class="mt-1 max-w-lg overflow-x-auto whitespace-pre-wrap rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ json_encode($entry->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-3 text-zinc-500">Zatiaľ žiadne záznamy.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $this->entries->links() }}
    </flux:card>
</div>
