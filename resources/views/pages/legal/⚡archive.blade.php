<?php

use App\Enums\LegalDocumentType;
use App\Services\Legal\LegalDocuments;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::public')] class extends Component {
    public LegalDocumentType $type;

    public function mount(string $slug): void
    {
        $this->type = LegalDocumentType::fromSlug($slug) ?? abort(404);
    }

    public function rendering($view): void
    {
        $view->title(__('Archív – :type', ['type' => $this->type->shortLabel()]));
    }

    #[Computed]
    public function versions(): Collection
    {
        return app(LegalDocuments::class)->history($this->type);
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <flux:heading size="xl" level="1" class="font-display">{{ __('Archív: :type', ['type' => $type->label()]) }}</flux:heading>
    <flux:text>{{ __('Každá publikovaná verzia zostáva dostupná. Objednávka sa riadi verziou platnou v čase jej uzavretia.') }}</flux:text>

    <flux:card>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-3">{{ __('Verzia') }}</th><th class="py-1 pe-3">{{ __('Účinná od') }}</th><th class="py-1 pe-3">{{ __('Stav') }}</th><th class="py-1">{{ __('Zmena') }}</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->versions as $version)
                    <tr wire:key="v-{{ $version->id }}">
                        <td class="py-2 pe-3"><a href="{{ route('legal.show', ['slug' => $type->slug(), 'version' => $version->version]) }}" wire:navigate class="underline">{{ __('v:version', ['version' => $version->version]) }}</a></td>
                        <td class="py-2 pe-3">{{ $version->effective_at?->timezone(config('recipes.default_timezone'))->format('j. n. Y') }}</td>
                        <td class="py-2 pe-3"><flux:badge size="sm" :color="$version->state->badgeColor()">{{ $version->state->label() }}</flux:badge></td>
                        <td class="py-2 text-xs">{{ $version->change_summary }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-zinc-500">{{ __('Zatiaľ nebola publikovaná žiadna verzia.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>

    <flux:button :href="route('legal.show', ['slug' => $type->slug()])" wire:navigate variant="ghost" size="sm" icon="chevron-left">{{ __('Aktuálna verzia') }}</flux:button>
</div>
