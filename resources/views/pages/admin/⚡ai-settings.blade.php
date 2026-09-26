<?php

use App\Models\AiCostRate;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ImageProfile;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Runtime AI profile: model, reasoning effort, default image profile, limits, budget alarm and the kill switch.
 * Values are stored in app_settings and audited; .env keeps the defaults.
 */
new #[Layout('layouts::admin')] #[Title('AI nastavenia')] class extends Component {
    public bool $enabled = true;

    public string $text_model = '';

    public string $text_reasoning_effort = 'default';

    public string $image_model = '';

    public string $image_profile = 'image_standard_v1';

    public int $daily_text_limit = 30;

    public int $daily_image_limit = 10;

    public string $monthly_budget_usd = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->fillFrom(app(AiSettings::class)->toArray());
    }

    /** @param array<string, mixed> $values */
    private function fillFrom(array $values): void
    {
        $this->enabled = (bool) $values['enabled'];
        $this->text_model = (string) ($values['text_model'] ?? '');
        $this->text_reasoning_effort = (string) $values['text_reasoning_effort'];
        $this->image_model = (string) ($values['image_model'] ?? '');
        $this->image_profile = (string) $values['image_profile'];
        $this->daily_text_limit = (int) $values['daily_text_limit'];
        $this->daily_image_limit = (int) $values['daily_image_limit'];
        $this->monthly_budget_usd = Money::microToUsdString($values['monthly_budget_micro_usd']);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function defaults(): array
    {
        return app(AiSettings::class)->defaults();
    }

    /** @return array{text: int|null, text_rate: string|null, profiles: array<string, array{cost: int|null, rate: string|null}>} */
    #[Computed]
    public function projection(): array
    {
        $settings = app(AiSettings::class);
        $calc = app(AiCostCalculator::class);

        // Illustrative text operation from the specification: 3 000 input and 2 000 billed output tokens.
        $textRate = $calc->rateFor($settings->textProvider(), $this->text_model ?: null, AiCostRate::MODALITY_TEXT, null, null, now());

        $profiles = [];
        foreach (ImageProfile::cases() as $profile) {
            $rate = $calc->rateFor($settings->imageProvider(), $this->image_model ?: null, AiCostRate::MODALITY_IMAGE, $profile->quality(), $profile->pixelSize(), now());
            $profiles[$profile->value] = ['cost' => $rate ? $calc->estimateImage($rate, 1) : null, 'rate' => $rate?->label()];
        }

        return [
            'text' => $textRate ? $calc->estimateText($textRate, 3000, 2000) : null,
            'text_rate' => $textRate?->label(),
            'profiles' => $profiles,
        ];
    }

    public function save(): void
    {
        $this->authorize('platform-admin');

        $validated = $this->validate([
            'enabled' => ['boolean'],
            'text_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]*$/'],
            'text_reasoning_effort' => ['required', 'in:'.implode(',', AiSettings::REASONING_EFFORTS)],
            'image_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]*$/'],
            'image_profile' => ['required', 'in:'.implode(',', array_map(fn (ImageProfile $p) => $p->value, ImageProfile::selectable()))],
            'daily_text_limit' => ['required', 'integer', 'min:0', 'max:10000'],
            'daily_image_limit' => ['required', 'integer', 'min:0', 'max:10000'],
            'monthly_budget_usd' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $budget = Money::parseUsdToMicro($validated['monthly_budget_usd'] ?? null);
        if (trim((string) ($validated['monthly_budget_usd'] ?? '')) !== '' && $budget === null) {
            $this->addError('monthly_budget_usd', 'Zadaj sumu v USD, napr. 5 alebo 2,50.');

            return;
        }

        app(AiSettings::class)->update([
            'enabled' => (bool) $validated['enabled'],
            'text_model' => $validated['text_model'] ?? '',
            'text_reasoning_effort' => $validated['text_reasoning_effort'],
            'image_model' => $validated['image_model'] ?? '',
            'image_profile' => $validated['image_profile'],
            'daily_text_limit' => (int) $validated['daily_text_limit'],
            'daily_image_limit' => (int) $validated['daily_image_limit'],
            'monthly_budget_micro_usd' => $budget,
        ], auth()->user(), $this->reason !== '' ? $this->reason : null);

        $this->reason = '';
        $this->fillFrom(app(AiSettings::class)->toArray());
        unset($this->projection);
        Flux::toast(variant: 'success', text: 'AI nastavenia uložené. Už zaradené úlohy bežia so svojím pôvodným profilom.');
    }

    public function resetToDefaults(): void
    {
        $this->authorize('platform-admin');

        app(AiSettings::class)->update(array_fill_keys(array_keys(AiSettings::KEYS), null), auth()->user(), 'reset na predvolené hodnoty z .env');
        $this->fillFrom(app(AiSettings::class)->toArray());
        unset($this->projection);
        Flux::toast(variant: 'success', text: 'Nastavenia vrátené na hodnoty z konfigurácie.');
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="AI nastavenia" subtitle="Model, váha reasoningu a predvolený profil obrázkov pre nové úlohy. Zmena sa audituje; už zaradené úlohy bežia s pôvodným profilom." :back="route('admin.ai')" />

    <form wire:submit="save" class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <flux:card class="space-y-4">
                <flux:heading size="lg" class="font-display">Prevádzka</flux:heading>
                <flux:checkbox wire:model="enabled" label="AI funkcie zapnuté" description="Vypnutie (kill switch) zastaví nové AI úlohy, zachová dáta a používateľom ukáže dočasnú nedostupnosť. Ručná úprava receptov funguje ďalej." />
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg" class="font-display">Text</flux:heading>
                <flux:input wire:model="text_model" label="Textový model" placeholder="{{ $this->defaults['text_model'] ?? 'predvolený model poskytovateľa' }}" description="Predvolené z .env: {{ $this->defaults['text_model'] ?? '(nenastavené)' }}. Prázdne = predvolený model poskytovateľa." />
                <flux:radio.group wire:model="text_reasoning_effort" label="Váha reasoningu (reasoning effort)" description="Platí pre reasoning modely (gpt-5/gpt-6). Vyššia váha = viac účtovaných výstupných tokenov a dlhšie trvanie. Predvolené z .env: {{ $this->defaults['text_reasoning_effort'] }}.">
                    <flux:radio value="default" label="Podľa poskytovateľa" description="Parameter sa neposiela." />
                    <flux:radio value="low" label="Low" description="Najlacnejšie a najrýchlejšie; vhodné na jazykové úpravy." />
                    <flux:radio value="medium" label="Medium" description="Vyváženie kvality a ceny." />
                    <flux:radio value="high" label="High" description="Najdrahšie; iba ak medium nestačí." />
                </flux:radio.group>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg" class="font-display">Obrázky</flux:heading>
                <flux:input wire:model="image_model" label="Obrázkový model" placeholder="{{ $this->defaults['image_model'] ?? 'predvolený model poskytovateľa' }}" description="Predvolené z .env: {{ $this->defaults['image_model'] ?? '(nenastavené)' }}." />
                <flux:radio.group wire:model="image_profile" label="Predvolený profil obrázkov" description="Pre nové bezplatné/skúšobné použitia. Profil určuje kvalitu, rozmer 1024 × 1024 a počet; klient ho nevyberá – server ho odvodí z dostupných nárokov (Standard nárok sa nikdy neminie na Economy a naopak). Predvolené z .env RECIPES_AI_IMAGE_QUALITY: {{ ImageProfile::from($this->defaults['image_profile'])->label() }}." data-test="image-profile">
                    @foreach (ImageProfile::selectable() as $profile)
                        @php($cost = $this->projection['profiles'][$profile->value]['cost'] ?? null)
                        <flux:radio :value="$profile->value" :label="$profile->label().' ('.$profile->value.')'" :description="$profile->description().' · '.($cost !== null ? Money::microUsd($cost).' / obrázok' : 'bez sadzby v cenníku').' · spotrebúva '.$profile->usageKind()->label()" />
                    @endforeach
                </flux:radio.group>
                <flux:text class="text-xs">{{ ImageProfile::HighV1->label() }} ({{ ImageProfile::HighV1->value }}): {{ ImageProfile::HighV1->description() }}{{ ($this->projection['profiles'][ImageProfile::HighV1->value]['cost'] ?? null) !== null ? ' · '.Money::microUsd($this->projection['profiles'][ImageProfile::HighV1->value]['cost']).' / obrázok' : '' }}.</flux:text>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg" class="font-display">Limity a rozpočet</flux:heading>
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="daily_text_limit" type="number" min="0" label="Denný limit textov / domácnosť" />
                    <flux:input wire:model="daily_image_limit" type="number" min="0" label="Denný limit obrázkov / domácnosť" />
                    <flux:input wire:model="monthly_budget_usd" label="Mesačný rozpočet (USD)" placeholder="napr. 5" description="Iba alarm na prehľade, nikdy neskracuje zaplatené použitia." />
                </div>
                <flux:text class="text-xs">Denné limity sú dočasná ochrana pred etapou „granty a ledger“ (predplatné 30 textov a 5 obrázkov za obdobie).</flux:text>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:input wire:model="reason" label="Dôvod zmeny (do auditu)" placeholder="napr. test kvality medium vs. low" />
                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary">Uložiť nastavenia</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="resetToDefaults" wire:confirm="Vrátiť všetky AI nastavenia na hodnoty z .env?">Vrátiť na predvolené</flux:button>
                </div>
            </flux:card>
        </div>

        <div class="space-y-4">
            <flux:card class="space-y-3">
                <flux:heading size="lg" class="font-display">Odhad ceny jednej operácie</flux:heading>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-zinc-500">Text (3 000 vstupných + 2 000 výstupných tokenov)</dt>
                        <dd class="font-medium">{{ $this->projection['text'] !== null ? Money::microUsd($this->projection['text']) : 'chýba sadzba pre „'.($this->text_model ?: 'predvolený').'“' }}</dd>
                        @if ($this->projection['text_rate']) <dd class="text-xs text-zinc-500">{{ $this->projection['text_rate'] }}</dd> @endif
                    </div>
                    @foreach (ImageProfile::cases() as $profile)
                        @php($p = $this->projection['profiles'][$profile->value])
                        <div>
                            <dt class="text-zinc-500">Obrázok {{ $profile->label() }} · {{ $profile->quality() }} · {{ $profile->pixelSize() }}</dt>
                            <dd class="font-medium">{{ $p['cost'] !== null ? Money::microUsd($p['cost']) : 'chýba sadzba pre „'.($this->image_model ?: 'predvolený').'“' }}</dd>
                            @if ($p['rate']) <dd class="text-xs text-zinc-500">{{ $p['rate'] }}</dd> @endif
                        </div>
                    @endforeach
                </dl>
                <flux:text class="text-xs">Odhad z cenníka bez vstupov obrázka; skutočné usage je autoritatívne. Sadzby spravuješ v <a href="{{ route('admin.ai.rates') }}" class="underline" wire:navigate>Cenníku AI</a>.</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:heading size="lg" class="font-display">Kľúče a poskytovateľ</flux:heading>
                <flux:text class="text-sm">API kľúče a poskytovateľ (`RECIPES_AI_TEXT_PROVIDER`, `OPENAI_API_KEY`) sa menia iba v .env na serveri – nikdy cez administráciu.</flux:text>
            </flux:card>
        </div>
    </form>
</div>
