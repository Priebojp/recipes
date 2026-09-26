<?php

use App\Enums\LegalDocumentType;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Services\Legal\CheckoutReadiness;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Legal documents: versions, publication, operator identity and the checkout readiness checklist.
 */
new #[Layout('layouts::admin')] #[Title('Právne dokumenty')] class extends Component {
    /** @var array<string, string> */
    public array $operator = [];

    public string $operator_reason = '';

    public string $reason = '';

    public function mount(OperatorIdentity $identity): void
    {
        $this->operator = $identity->all();
    }

    #[Computed]
    public function blockers(): array
    {
        return app(CheckoutReadiness::class)->blockers();
    }

    /** @return array<string, array{type: LegalDocumentType, current: ?LegalDocumentVersion, draft: ?LegalDocumentVersion, acceptances: int}> */
    #[Computed]
    public function documents(): array
    {
        $documents = app(LegalDocuments::class);
        $rows = [];
        foreach (LegalDocumentType::cases() as $type) {
            $current = $documents->current($type);
            $rows[$type->value] = [
                'type' => $type,
                'current' => $current,
                'draft' => $documents->draft($type),
                'acceptances' => $current ? $current->acceptances()->count() : 0,
            ];
        }

        return $rows;
    }

    #[Computed]
    public function acceptances(): Collection
    {
        return LegalAcceptance::query()->with(['documentVersion', 'user:id,email'])->latest('id')->limit(30)->get();
    }

    public function saveOperator(OperatorIdentity $identity): void
    {
        $this->authorize('platform-admin');
        $rules = ['operator_reason' => ['required', 'string', 'min:3', 'max:500']];
        foreach (OperatorIdentity::FIELDS as $field => $meta) {
            $rules['operator.'.$field] = ['nullable', 'string', 'max:300'];
        }
        $rules['operator.support_email'][] = 'email';
        $rules['operator.complaints_email'][] = 'email';
        $rules['operator.privacy_email'][] = 'email';
        $this->validate($rules);

        $identity->update($this->operator, auth()->user(), $this->operator_reason);
        $this->operator = $identity->all();
        $this->reset('operator_reason');
        unset($this->blockers);
        Flux::toast(variant: 'success', text: 'Údaje prevádzkovateľa uložené.');
    }

    public function newDraft(string $type, LegalDocuments $documents): void
    {
        $this->authorize('platform-admin');
        try {
            $draft = $documents->newDraft(LegalDocumentType::from($type), by: auth()->user());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.legal.document', ['version' => $draft->id], navigate: true);
    }

    public function archive(int $id, LegalDocuments $documents): void
    {
        $this->authorize('platform-admin');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            $documents->archive(LegalDocumentVersion::query()->findOrFail($id), $this->reason, auth()->user());
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        $this->reset('reason');
        unset($this->documents, $this->blockers);
        Flux::toast(variant: 'success', text: 'Verzia archivovaná; stránka teraz hlási, že sa dokument pripravuje.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Právne dokumenty" subtitle="Verzie, publikácia so schválením, história akceptácií a identita prevádzkovateľa. Publikovaná verzia je nemenná; zmena je nová verzia." />

    @if ($this->blockers !== [])
        <flux:callout icon="exclamation-triangle" variant="warning" data-test="legal-blockers">
            <flux:callout.heading>Platený checkout je zablokovaný</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-inside list-disc">
                    @foreach ($this->blockers as $blocker) <li>{{ $blocker }}</li> @endforeach
                </ul>
                Bezplatné funkcie a recepty to neobmedzuje.
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="check-circle" variant="success" data-test="legal-ready">Identita prevádzkovateľa a povinné dokumenty sú publikované; checkout môže bežať (Stripe price ID musí mať katalóg).</flux:callout>
    @endif

    <flux:card class="space-y-3" data-test="legal-documents">
        <flux:heading size="lg" class="font-display">Dokumenty</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Dokument</th><th class="py-1 pe-2">Publikovaná</th><th class="py-1 pe-2">Akceptácie</th><th class="py-1 pe-2">Návrh</th><th class="py-1">Akcie</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->documents as $row)
                    <tr wire:key="doc-{{ $row['type']->value }}" class="align-top">
                        <td class="py-2 pe-2 font-medium">{{ $row['type']->label() }}<div class="text-xs font-normal text-zinc-500">/{{ $row['type']->slug() }}</div></td>
                        <td class="py-2 pe-2">
                            @if ($row['current'])
                                v{{ $row['current']->version }} · účinná {{ $row['current']->effective_at?->format('j. n. Y') }}<br>
                                <span class="text-xs text-zinc-500">schválil {{ $row['current']->approver?->email }} {{ $row['current']->approved_at?->format('j. n. Y') }}</span>
                                <a href="{{ route('admin.legal.document', ['version' => $row['current']->id]) }}" wire:navigate class="ms-1 text-xs underline">zobraziť</a>
                            @else
                                <flux:badge size="sm" color="amber">žiadna</flux:badge>
                            @endif
                        </td>
                        <td class="py-2 pe-2 tabular-nums">{{ $row['acceptances'] }}</td>
                        <td class="py-2 pe-2">
                            @if ($row['draft'])
                                <a href="{{ route('admin.legal.document', ['version' => $row['draft']->id]) }}" wire:navigate class="underline">v{{ $row['draft']->version }}</a>
                                @if ($row['draft']->hasPlaceholders()) <flux:badge size="sm" color="amber">{{ count($row['draft']->placeholders()) }} nevyplnených</flux:badge> @else <flux:badge size="sm" color="green">bez placeholderov</flux:badge> @endif
                            @else
                                –
                            @endif
                        </td>
                        <td class="py-2">
                            <div class="flex flex-wrap gap-1">
                                @unless ($row['draft'])
                                    <flux:button size="xs" wire:click="newDraft('{{ $row['type']->value }}')" data-test="new-draft-{{ $row['type']->value }}">Nová verzia</flux:button>
                                @endunless
                                @if ($row['current'])
                                    <flux:button size="xs" variant="ghost" :href="route('legal.show', ['slug' => $row['type']->slug()])" target="_blank">Stránka</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <details class="text-sm">
            <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">Archivovať publikovanú verziu (stránka bude hlásiť „pripravuje sa“)</summary>
            <div class="mt-2 flex flex-wrap items-end gap-2">
                <flux:input wire:model="reason" label="Dôvod" class="w-72" />
                @foreach ($this->documents as $row)
                    @if ($row['current'])
                        <flux:button size="sm" variant="danger" wire:click="archive({{ $row['current']->id }})" wire:confirm="Archivovať {{ $row['type']->shortLabel() }} v{{ $row['current']->version }}?">{{ $row['type']->shortLabel() }} v{{ $row['current']->version }}</flux:button>
                    @endif
                @endforeach
            </div>
        </details>
    </flux:card>

    <flux:card class="space-y-3" data-test="operator-form">
        <flux:heading size="lg" class="font-display">Prevádzkovateľ</flux:heading>
        <flux:text class="text-sm">Povinné vstupy od prevádzkovateľa (zadanie kap. 9). Nič sa nevymýšľa; placeholdery v dokumentoch sa dopĺňajú z týchto polí.</flux:text>
        <form wire:submit="saveOperator" class="grid gap-3 sm:grid-cols-2">
            @foreach (App\Services\Legal\OperatorIdentity::FIELDS as $field => $meta)
                <flux:input wire:model="operator.{{ $field }}" :label="$meta['label'].($meta['required'] ? ' *' : '')" :description="$meta['hint'] ?? null" data-test="operator-{{ $field }}" />
            @endforeach
            <flux:input wire:model="operator_reason" label="Dôvod zmeny *" class="sm:col-span-2" />
            <div class="sm:col-span-2"><flux:button type="submit" variant="primary" data-test="operator-save">Uložiť</flux:button></div>
        </form>
    </flux:card>

    <flux:card class="space-y-2">
        <flux:heading size="lg" class="font-display">Posledné akceptácie</flux:heading>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Kedy</th><th class="py-1 pe-2">Dokument</th><th class="py-1 pe-2">Akt</th><th class="py-1 pe-2">Kto</th><th class="py-1">Objednávka / poznámky</th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @forelse ($this->acceptances as $acceptance)
                    <tr wire:key="acc-{{ $acceptance->id }}">
                        <td class="py-1.5 pe-2 whitespace-nowrap">{{ $acceptance->accepted_at->setTimezone(config('recipes.default_timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="py-1.5 pe-2">{{ $acceptance->documentVersion?->label() }}</td>
                        <td class="py-1.5 pe-2">{{ $acceptance->action->label() }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ $acceptance->user?->email ?? $acceptance->email ?? '–' }}</td>
                        <td class="py-1.5 text-xs">@if ($acceptance->order_id)<a href="{{ route('admin.orders.show', $acceptance->order_id) }}" class="underline" wire:navigate>#{{ $acceptance->order_id }}</a>@endif @if ($acceptance->acknowledgements) {{ json_encode($acceptance->acknowledgements) }} @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-zinc-500">Žiadne akceptácie.</td></tr>
                @endforelse
            </tbody>
        </table>
    </flux:card>
</div>
