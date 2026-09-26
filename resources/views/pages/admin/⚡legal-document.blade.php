<?php

use App\Models\LegalDocumentVersion;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One document version: a draft is edited, filled from the operator identity and published with an approval note;
 * a published or archived version is read-only.
 */
new #[Layout('layouts::admin')] class extends Component {
    public LegalDocumentVersion $version;

    public string $title = '';

    public string $content = '';

    public string $change_summary = '';

    public string $effective_at = '';

    public string $approval_note = '';

    public function mount(LegalDocumentVersion $version): void
    {
        $this->version = $version;
        $this->title = $version->title;
        $this->content = $version->content;
        $this->change_summary = (string) $version->change_summary;
        $this->effective_at = $version->effective_at?->timezone(config('recipes.default_timezone'))->format('Y-m-d') ?? '';
    }

    public function rendering($view): void
    {
        $view->title($this->version->label());
    }

    public function save(LegalDocuments $documents): void
    {
        $this->authorize('platform-admin');
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:200000'],
            'change_summary' => ['nullable', 'string', 'max:500'],
            'effective_at' => ['nullable', 'date'],
        ]);

        try {
            $documents->updateDraft($this->version, [
                'title' => $this->title,
                'content' => $this->content,
                'change_summary' => $this->change_summary ?: null,
                'effective_at' => $this->effective_at !== '' ? \Carbon\CarbonImmutable::parse($this->effective_at, config('recipes.default_timezone'))->startOfDay() : null,
            ], auth()->user());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->version->refresh();
        Flux::toast(variant: 'success', text: 'Návrh uložený.');
    }

    public function fillOperator(OperatorIdentity $identity): void
    {
        $this->authorize('platform-admin');
        $this->content = $identity->fillPlaceholders($this->content);
        Flux::toast(variant: 'success', text: 'Známe placeholdery doplnené z údajov prevádzkovateľa – skontroluj a ulož.');
    }

    public function publish(LegalDocuments $documents): void
    {
        $this->authorize('platform-admin');
        $this->validate(['approval_note' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->save($documents);

        try {
            $documents->publish($this->version->fresh(), $this->approval_note, auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('approval_note', $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.legal', navigate: true);
    }
}; ?>

<div class="space-y-6">
    <x-page-header :title="$version->label()" :back="route('admin.legal')" :subtitle="$version->type->label().' · '.$version->state->label()" />

    @if ($version->isDraft())
        <flux:card class="space-y-4" data-test="draft-form">
            @if ($version->hasPlaceholders())
                <flux:callout icon="exclamation-triangle" variant="warning" data-test="draft-placeholders">
                    <flux:callout.heading>Nevyplnené údaje ({{ count($version->placeholders()) }})</flux:callout.heading>
                    <flux:callout.text>{{ implode(' · ', $version->placeholders()) }}</flux:callout.text>
                </flux:callout>
            @endif
            <form wire:submit="save" class="space-y-4">
                <flux:input wire:model="title" label="Názov" />
                <flux:textarea wire:model="content" label="Text (Markdown)" rows="24" class="font-mono text-sm" data-test="draft-content" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="change_summary" label="Zhrnutie zmeny (v archíve)" />
                    <flux:input wire:model="effective_at" type="date" label="Účinnosť od" description="Prázdne = pri publikovaní." />
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary" data-test="draft-save">Uložiť návrh</flux:button>
                    <flux:button type="button" wire:click="fillOperator" data-test="draft-fill">Doplniť údaje prevádzkovateľa</flux:button>
                </div>
            </form>
        </flux:card>

        <flux:card class="space-y-3" data-test="publish-form">
            <flux:heading size="lg" class="font-display">Publikovať</flux:heading>
            <flux:text class="text-sm">Publikovaním potvrdzuješ, že text prešiel kontrolou (právne schválenie je vstup prevádzkovateľa, nie aplikácie). Predchádzajúca publikovaná verzia sa archivuje a zostane v archíve; objednávky si držia svoju verziu.</flux:text>
            <flux:input wire:model="approval_note" label="Poznámka o schválení (kto, kedy, čo skontroloval)" data-test="approval-note" />
            <flux:button variant="primary" wire:click="publish" wire:confirm="Publikovať {{ $version->label() }}? Text sa stane nemenným." data-test="publish">Publikovať verziu {{ $version->version }}</flux:button>
        </flux:card>
    @else
        <flux:card class="space-y-2">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-zinc-500">Stav</dt><dd><flux:badge size="sm" :color="$version->state->badgeColor()">{{ $version->state->label() }}</flux:badge></dd>
                <dt class="text-zinc-500">Účinná od</dt><dd>{{ $version->effective_at?->format('j. n. Y') }}</dd>
                <dt class="text-zinc-500">Publikovaná</dt><dd>{{ $version->published_at?->format('j. n. Y H:i') }} · schválil {{ $version->approver?->email }}: {{ $version->approval_note }}</dd>
                <dt class="text-zinc-500">Checksum</dt><dd class="font-mono text-xs">{{ $version->checksum }}</dd>
                <dt class="text-zinc-500">Akceptácie</dt><dd>{{ $version->acceptances()->count() }}</dd>
            </dl>
        </flux:card>
    @endif

    <flux:card>
        <flux:heading size="lg" class="mb-3 font-display">Náhľad</flux:heading>
        <div class="legal-prose">
            {!! \Illuminate\Support\Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
        </div>
    </flux:card>
</div>
