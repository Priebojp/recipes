<?php

use App\Concerns\PasswordValidationRules;
use App\Enums\ConsentCategory;
use App\Enums\LegalDocumentType;
use App\Livewire\Actions\Logout;
use App\Models\LegalAcceptance;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Consent\ConsentPolicy;
use App\Services\Legal\LegalDocuments;
use App\Services\Privacy\AccountErasure;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Súkromie: documents and acceptances, cookie decision, exports, requests and account erasure with its
 * impact shown before confirmation (specification chapter 11, "Používateľské funkcie").
 */
new #[Title('Súkromie')] class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /** household id => 'erase' | user id to transfer to */
    public array $householdChoice = [];

    public string $erasureMessage = '';

    #[Computed]
    public function documents(): array
    {
        $documents = app(LegalDocuments::class);

        return array_map(fn (LegalDocumentType $type) => ['type' => $type, 'current' => $documents->current($type)], LegalDocumentType::cases());
    }

    #[Computed]
    public function acceptances(): Collection
    {
        return LegalAcceptance::query()->where('user_id', auth()->id())->with('documentVersion')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function requests(): Collection
    {
        return PrivacyRequest::query()->where('user_id', auth()->id())->latest('id')->get();
    }

    #[Computed]
    public function decision(): ?array
    {
        return app(ConsentPolicy::class)->decision(request());
    }

    #[Computed]
    public function hasOptional(): bool
    {
        return app(ConsentPolicy::class)->offeredCategories() !== [];
    }

    #[Computed]
    public function impact(): array
    {
        return app(AccountErasure::class)->impact(auth()->user());
    }

    /** @return Collection<int, User> members who could take over a household */
    public function membersOf(int $householdId): Collection
    {
        return User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('household_memberships')->where('household_id', $householdId)->where('user_id', '!=', auth()->id()))->get();
    }

    public function erase(Logout $logout, AccountErasure $erasure): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
            'erasureMessage' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = auth()->user();
        $transferTo = null;
        foreach ($this->householdChoice as $householdId => $choice) {
            if ($choice !== 'erase' && (int) $choice > 0) {
                $transferTo = User::query()->find((int) $choice);
            }
        }

        $logout();
        $erasure->erase($user, $transferTo, $this->erasureMessage ?: null);

        session()->flash('status', 'Účet bol vymazaný. Potvrdenie sme poslali e-mailom.');
        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout heading="Súkromie a podmienky" subheading="Dokumenty, ktoré si prijal(a), tvoja voľba cookies, export údajov a vymazanie účtu.">
        <div class="my-6 space-y-6">
            <flux:card class="space-y-2" data-test="privacy-documents">
                <flux:heading size="lg" class="font-display">Právne dokumenty</flux:heading>
                <ul class="space-y-1 text-sm">
                    @foreach ($this->documents as $row)
                        <li>
                            <a href="{{ route('legal.show', ['slug' => $row['type']->slug()]) }}" wire:navigate class="underline">{{ $row['type']->label() }}</a>
                            @if ($row['current']) <span class="text-zinc-500">· v{{ $row['current']->version }}, účinná od {{ $row['current']->effective_at?->format('j. n. Y') }}</span> @else <span class="text-zinc-500">· pripravuje sa</span> @endif
                        </li>
                    @endforeach
                </ul>
                @if ($this->acceptances->isNotEmpty())
                    <flux:heading class="mt-2 font-display">Tvoje akceptácie</flux:heading>
                    <ul class="space-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                        @foreach ($this->acceptances as $acceptance)
                            <li wire:key="acc-{{ $acceptance->id }}">{{ $acceptance->accepted_at->timezone(config('recipes.default_timezone'))->format('j. n. Y H:i') }} · {{ $acceptance->documentVersion?->label() }} · {{ $acceptance->action->label() }}@if ($acceptance->order_id) · objednávka #{{ $acceptance->order_id }}@endif @if (($acceptance->acknowledgements['early_performance_requested'] ?? false)) · žiadosť o skoré plnenie @endif</li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>

            <flux:card class="space-y-2" data-test="privacy-cookies">
                <flux:heading size="lg" class="font-display">Cookies a voliteľné služby</flux:heading>
                @if (! $this->hasOptional)
                    <flux:text class="text-sm">Nepoužívame žiadne voliteľné služby, preto nič nežiadame. <a href="{{ route('legal.show', ['slug' => 'cookies']) }}" wire:navigate class="underline">Zoznam nevyhnutných technológií</a>.</flux:text>
                @elseif ($this->decision)
                    <flux:text class="text-sm">
                        Voľba z {{ \Carbon\CarbonImmutable::parse($this->decision['at'])->timezone(config('recipes.default_timezone'))->format('j. n. Y H:i') }}:
                        @foreach ($this->decision['categories'] as $key => $on) {{ ConsentCategory::from($key)->label() }} {{ $on ? 'zapnuté' : 'vypnuté' }}{{ $loop->last ? '' : ', ' }} @endforeach
                    </flux:text>
                    <flux:button size="sm" data-consent-open>Zmeniť alebo odvolať</flux:button>
                @else
                    <flux:text class="text-sm">Zatiaľ si nerozhodol(a); voliteľné služby sú vypnuté.</flux:text>
                    <flux:button size="sm" data-consent-open>Nastavenia cookies</flux:button>
                @endif
            </flux:card>

            <flux:card class="space-y-2" data-test="privacy-export">
                <flux:heading size="lg" class="font-display">Export údajov</flux:heading>
                <flux:text class="text-sm">Domácnosť: ZIP s receptami, pôvodnými textami, chuťami, plánmi, históriou a obrázkami. Účet: JSON s profilom, členstvami, akceptáciami, voľbami cookies a žiadosťami.</flux:text>
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('export')" icon="arrow-down-tray" size="sm">Export domácnosti (ZIP)</flux:button>
                    <flux:button :href="route('privacy.export')" icon="arrow-down-tray" size="sm" variant="ghost">Export účtu (JSON)</flux:button>
                </div>
            </flux:card>

            @if ($this->requests->isNotEmpty())
                <flux:card class="space-y-2" data-test="privacy-requests">
                    <flux:heading size="lg" class="font-display">Tvoje žiadosti</flux:heading>
                    <ul class="space-y-0.5 text-sm">
                        @foreach ($this->requests as $request)
                            <li wire:key="req-{{ $request->id }}">#{{ $request->id }} · {{ $request->kind->label() }} · <flux:badge size="sm" :color="$request->status->badgeColor()">{{ $request->status->label() }}</flux:badge> · prijatá {{ $request->received_at->timezone(config('recipes.default_timezone'))->format('j. n. Y') }}, lehota do {{ $request->deadline_at->timezone(config('recipes.default_timezone'))->format('j. n. Y') }}</li>
                        @endforeach
                    </ul>
                </flux:card>
            @endif

            <flux:card class="space-y-3 border-red-200 dark:border-red-900" data-test="privacy-erasure">
                <flux:heading size="lg" class="font-display">Vymazanie účtu</flux:heading>
                @php($impact = $this->impact)
                <div class="space-y-2 text-sm">
                    @foreach ($impact['owned'] as $row)
                        <div wire:key="own-{{ $row['household']->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="font-medium">{{ $row['household']->name }} (si vlastník)</div>
                            <ul class="mt-1 list-inside list-disc text-xs text-zinc-600 dark:text-zinc-400">
                                <li>Recepty, fotografie, profily, plány a história domácnosti budú zmazané.</li>
                                @if ($row['plus_until']) <li>Plus zaplatené do {{ $row['plus_until']->timezone(config('recipes.default_timezone'))->format('j. n. Y') }} skončí bez náhrady{{ $row['renewal_active'] ? '; obnovovanie vypneme' : '' }}.</li> @endif
                                @foreach ($row['unused_purchased'] as $kind => $n) <li>Nevyužité dokúpené použitia: {{ $n }} × {{ App\Enums\UsageKind::from($kind)->label() }} – prepadnú.</li> @endforeach
                                @if ($row['financial_records']) <li>Účtovné doklady k objednávkam zostanú uchované bez receptov a mien (zákonná povinnosť).</li> @endif
                                @if ($row['other_members'] > 0) <li>Ďalší členovia domácnosti: {{ $row['other_members'] }}.</li> @endif
                            </ul>
                            @if ($row['other_members'] > 0)
                                <flux:radio.group wire:model="householdChoice.{{ $row['household']->id }}" label="Čo s domácnosťou?" class="mt-2" data-test="household-choice-{{ $row['household']->id }}">
                                    <flux:radio value="erase" label="Zrušiť domácnosť aj pre ostatných členov" />
                                    @foreach ($this->membersOf($row['household']->id) as $member)
                                        <flux:radio :value="(string) $member->id" :label="'Previesť na člena '.$member->name" />
                                    @endforeach
                                </flux:radio.group>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($impact['member_of'] as $household)
                        <div wire:key="mem-{{ $household->id }}" class="rounded-lg border border-zinc-200 p-3 text-xs dark:border-zinc-700">{{ $household->name }}: tvoje členstvo skončí, recepty domácnosti zostanú jej vlastníkovi; tvoj profil stravníka sa anonymizuje.</div>
                    @endforeach
                </div>

                <form wire:submit="erase" class="space-y-3">
                    <flux:textarea wire:model="erasureMessage" label="Poznámka k žiadosti (nepovinné)" rows="2" />
                    <flux:input wire:model="password" type="password" label="Heslo na potvrdenie" viewable data-test="erase-password" />
                    <flux:button type="submit" variant="danger" wire:confirm="Naozaj vymazať účet? Túto akciu nemožno vrátiť." data-test="erase-submit">Vymazať účet</flux:button>
                </form>
                <flux:text class="text-xs">Žiadosť sa vybaví ihneď a potvrdenie pošleme e-mailom. Zálohy sa obmieňajú; vybavené výmazy sa z nich neobnovujú.</flux:text>
            </flux:card>
        </div>
    </x-pages::settings.layout>
</section>
