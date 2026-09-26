<?php

use App\Enums\ConsentCategory;
use App\Models\ConsentReceipt;
use App\Models\ConsentService;
use App\Services\Admin\AdminAuditor;
use App\Services\Consent\ConsentPolicy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Register of services and technologies. The cookies page and the banner are generated from it; enabling an
 * optional service or changing its purpose / storage bumps the consent version, so visitors decide again.
 */
new #[Layout('layouts::admin')] #[Title('Služby a cookies')] class extends Component {
    public int $editing = 0;

    public bool $creating = false;

    public string $key = '';

    public string $name = '';

    public string $provider = '';

    public string $category = 'necessary';

    public string $purpose = '';

    public string $retention = '';

    public string $location = '';

    /** one entry per line: name | kind | domain | duration | purpose */
    public string $storage = '';

    public string $loader = '';

    public bool $enabled = false;

    public string $reason = '';

    #[Computed]
    public function services(): Collection
    {
        return ConsentService::query()->orderBy('category')->orderBy('name')->get();
    }

    #[Computed]
    public function policyVersion(): string
    {
        return app(ConsentPolicy::class)->version();
    }

    /** @return array<string, int> */
    #[Computed]
    public function receiptStats(): array
    {
        return ConsentReceipt::query()->where('created_at', '>=', now()->subDays(30))->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all();
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->creating = true;
    }

    public function edit(int $id): void
    {
        $service = ConsentService::query()->findOrFail($id);
        $this->resetForm();
        $this->editing = $service->id;
        $this->key = $service->key;
        $this->name = $service->name;
        $this->provider = $service->provider;
        $this->category = $service->category->value;
        $this->purpose = $service->purpose;
        $this->retention = (string) $service->retention;
        $this->location = (string) $service->location;
        $this->storage = collect($service->storage ?? [])->map(fn ($e) => implode(' | ', [$e['name'] ?? '', $e['kind'] ?? 'cookie', $e['domain'] ?? '', $e['duration'] ?? '', $e['purpose'] ?? '']))->implode("\n");
        $this->loader = $service->loader ? (string) json_encode($service->loader, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $this->enabled = $service->enabled;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(AdminAuditor $audit): void
    {
        $this->authorize('platform-admin');
        $validated = $this->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', 'unique:consent_services,key,'.$this->editing],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:necessary,analytics,marketing'],
            'purpose' => ['required', 'string', 'max:500'],
            'retention' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:200'],
            'storage' => ['nullable', 'string', 'max:5000'],
            'loader' => ['nullable', 'string', 'max:5000'],
            'enabled' => ['boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $storage = collect(preg_split('/\r?\n/', $this->storage))->map(fn ($line) => array_map('trim', explode('|', $line)))->filter(fn ($p) => ($p[0] ?? '') !== '')
            ->map(fn ($p) => ['name' => $p[0], 'kind' => $p[1] ?? 'cookie', 'domain' => $p[2] ?? '', 'duration' => $p[3] ?? '', 'purpose' => $p[4] ?? ''])->values()->all();

        $loader = null;
        if (trim($this->loader) !== '') {
            $loader = json_decode($this->loader, true);
            if (! is_array($loader) || ! in_array($loader['type'] ?? '', ['ga4', 'script'], true)) {
                $this->addError('loader', 'Loader musí byť JSON s "type": "ga4" (measurement_id) alebo "script" (src).');

                return;
            }
        }
        if ($this->category !== 'necessary' && $this->enabled && $loader === null) {
            $this->addError('loader', 'Voliteľná služba potrebuje loader, inak by sa nikdy nespustila.');

            return;
        }
        if ($this->category !== 'necessary' && $this->enabled && $loader['type'] === 'ga4' && empty($loader['measurement_id'])) {
            $this->addError('loader', 'Doplň measurement_id (G-XXXX) od zvoleného poskytovateľa.');

            return;
        }

        $values = [
            'key' => $this->key, 'name' => $this->name, 'provider' => $this->provider, 'category' => ConsentCategory::from($this->category),
            'purpose' => $this->purpose, 'retention' => $this->retention ?: null, 'location' => $this->location ?: null,
            'storage' => $storage, 'loader' => $loader, 'enabled' => $this->enabled, 'updated_by' => auth()->id(),
        ];

        if ($this->editing > 0) {
            $service = ConsentService::query()->findOrFail($this->editing);
            $before = $service->only(['name', 'provider', 'category', 'purpose', 'storage', 'loader', 'enabled']);
            $service->fill($values);
            $bump = $service->isDirty(['category', 'purpose', 'storage', 'loader']) || ($service->isDirty('enabled') && $service->enabled);
            if ($bump) {
                $service->consent_version = $service->consent_version + 1;
            }
            $service->save();
            $audit->record('consent.service.updated', $service, $before, $service->only(['name', 'provider', 'category', 'purpose', 'storage', 'loader', 'enabled', 'consent_version']), $this->reason, auth()->user());
        } else {
            $service = ConsentService::create($values);
            $audit->record('consent.service.created', $service, [], $service->only(['key', 'name', 'category', 'enabled']), $this->reason, auth()->user());
        }

        $this->resetForm();
        unset($this->services, $this->policyVersion);
        Flux::toast(variant: 'success', text: 'Služba uložená. '.($service->isOptional() && $service->enabled ? 'Návštevníci dostanú novú otázku na súhlas.' : ''));
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'creating', 'key', 'name', 'provider', 'category', 'purpose', 'retention', 'location', 'storage', 'loader', 'enabled', 'reason');
        $this->resetErrorBag();
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Služby a cookies" subtitle="Register technológií a poskytovateľov: účel, kategória, inventár cookies a úložísk, spôsob načítania. Stránka /cookies a lišta sa generujú odtiaľto." />

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card><flux:text class="text-xs uppercase">Verzia účelov</flux:text><div class="font-mono text-sm">{{ $this->policyVersion }}</div></flux:card>
        <flux:card><flux:text class="text-xs uppercase">Voliteľné zapnuté</flux:text><div class="text-2xl font-semibold">{{ $this->services->filter(fn ($s) => $s->enabled && $s->isOptional())->count() }}</div></flux:card>
        <flux:card><flux:text class="text-xs uppercase">Voľby za 30 dní</flux:text><div class="text-sm">prijaté {{ $this->receiptStats['accept_all'] ?? 0 }} · odmietnuté {{ $this->receiptStats['reject_all'] ?? 0 }} · vlastné {{ $this->receiptStats['custom'] ?? 0 }} · odvolané {{ $this->receiptStats['withdraw'] ?? 0 }}</div></flux:card>
    </div>

    <flux:card class="space-y-3 overflow-x-auto" data-test="services-table">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="font-display">Register</flux:heading>
            <flux:button size="sm" wire:click="startCreate" data-test="service-new">Nová služba</flux:button>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-2">Kľúč</th><th class="py-1 pe-2">Služba</th><th class="py-1 pe-2">Kategória</th><th class="py-1 pe-2">Účel</th><th class="py-1 pe-2">Úložisko</th><th class="py-1 pe-2">Stav</th><th class="py-1"></th></tr></thead>
            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                @foreach ($this->services as $service)
                    <tr wire:key="svc-{{ $service->id }}" class="align-top">
                        <td class="py-1.5 pe-2 font-mono text-xs">{{ $service->key }}</td>
                        <td class="py-1.5 pe-2">{{ $service->name }}<div class="text-xs text-zinc-500">{{ $service->provider }}</div></td>
                        <td class="py-1.5 pe-2">{{ $service->category->label() }}</td>
                        <td class="py-1.5 pe-2 max-w-xs text-xs">{{ $service->purpose }}</td>
                        <td class="py-1.5 pe-2 text-xs">{{ collect($service->storage ?? [])->pluck('name')->implode(', ') ?: '–' }}</td>
                        <td class="py-1.5 pe-2">
                            <flux:badge size="sm" :color="$service->enabled ? 'green' : 'zinc'">{{ $service->enabled ? 'zapnutá' : 'vypnutá' }}</flux:badge>
                            @if ($service->isOptional()) <span class="text-xs text-zinc-500">v{{ $service->consent_version }}</span> @endif
                        </td>
                        <td class="py-1.5"><flux:button size="xs" wire:click="edit({{ $service->id }})" data-test="service-edit-{{ $service->key }}">Upraviť</flux:button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </flux:card>

    @if ($creating || $editing > 0)
        <flux:card class="space-y-3" data-test="service-form">
            <flux:heading size="lg" class="font-display">{{ $editing > 0 ? 'Upraviť službu' : 'Nová služba' }}</flux:heading>
            <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="key" label="Kľúč (a-z, 0-9, -, _)" :disabled="$editing > 0" />
                <flux:input wire:model="name" label="Názov" />
                <flux:input wire:model="provider" label="Poskytovateľ (právnická osoba)" />
                <flux:select wire:model="category" label="Kategória">
                    @foreach (ConsentCategory::cases() as $c) <flux:select.option :value="$c->value">{{ $c->label() }}</flux:select.option> @endforeach
                </flux:select>
                <flux:input wire:model="purpose" label="Účel" class="sm:col-span-2" />
                <flux:input wire:model="retention" label="Uchovanie" />
                <flux:input wire:model="location" label="Miesto spracovania / prenos" />
                <flux:textarea wire:model="storage" label="Inventár – jeden riadok: názov | cookie alebo localStorage | doména | trvanie | účel" rows="4" class="font-mono text-xs sm:col-span-2" data-test="service-storage" />
                <flux:textarea wire:model="loader" label='Loader (iba voliteľné): {"type":"ga4","measurement_id":"G-…"} alebo {"type":"script","src":"https://…"}' rows="3" class="font-mono text-xs sm:col-span-2" data-test="service-loader" />
                <flux:checkbox wire:model="enabled" label="Zapnutá (voliteľná služba sa načíta až po súhlase návštevníka)" class="sm:col-span-2" data-test="service-enabled" />
                <flux:input wire:model="reason" label="Dôvod zmeny *" class="sm:col-span-2" data-test="service-reason" />
                <div class="flex gap-2 sm:col-span-2">
                    <flux:button type="submit" variant="primary" data-test="service-save">Uložiť</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="cancel">Zrušiť</flux:button>
                </div>
            </form>
        </flux:card>
    @endif
</div>
