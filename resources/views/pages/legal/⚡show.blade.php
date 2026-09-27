<?php

use App\Enums\ConsentCategory;
use App\Enums\LegalDocumentType;
use App\Models\LegalDocumentVersion;
use App\Services\Consent\ConsentPolicy;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Public legal page: the published version of a document (or an archived one by number). A draft is visible only
 * to a platform administrator, marked as a working draft with its unresolved placeholders.
 */
new #[Layout('layouts::public')] class extends Component {
    public LegalDocumentType $type;

    public ?LegalDocumentVersion $document = null;

    public bool $preview = false;

    public function mount(string $slug, ?int $version = null): void
    {
        $this->type = LegalDocumentType::fromSlug($slug) ?? abort(404);
        $documents = app(LegalDocuments::class);
        $isAdmin = auth()->user()?->isPlatformAdmin() ?? false;

        if ($version !== null) {
            $found = $documents->find($this->type, $version);
            if ($found === null || ($found->isDraft() && ! $isAdmin)) {
                abort(404);
            }
            $this->document = $found;
            $this->preview = $found->isDraft();

            return;
        }

        $this->document = $documents->current($this->type);
        if ($this->document === null && $isAdmin) {
            $this->document = $documents->latest($this->type);
            $this->preview = $this->document !== null;
        }
    }

    public function rendering($view): void
    {
        $view->title($this->type->label());
    }

    #[Computed]
    public function services()
    {
        return app(ConsentPolicy::class)->enabledServices()->groupBy(fn ($s) => $s->category->value);
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
    public function operator(): OperatorIdentity
    {
        return app(OperatorIdentity::class);
    }
}; ?>

<article class="mx-auto max-w-3xl space-y-6">
    <header class="space-y-2">
        <flux:heading size="xl" level="1" class="font-display">{{ $document?->title ?? $type->label() }}</flux:heading>
        @if ($document)
            <flux:text class="text-sm">
                {{ __('Verzia :version', ['version' => $document->version]) }}
                @if ($document->effective_at) · {{ __('účinná od :date', ['date' => $document->effective_at->timezone(config('recipes.default_timezone'))->format('j. n. Y')]) }} @endif
                @if ($document->published_at) · {{ __('publikovaná :date', ['date' => $document->published_at->timezone(config('recipes.default_timezone'))->format('j. n. Y')]) }} @endif
                @if ($document->archived_at) · <span class="text-amber-700 dark:text-amber-400">{{ __('archivovaná verzia, neplatí') }}</span> @endif
                · <a href="{{ route('legal.archive', ['slug' => $type->slug()]) }}" wire:navigate class="underline">{{ __('archív verzií') }}</a>
            </flux:text>
        @endif
    </header>

    @if ($document === null)
        <flux:callout icon="clock" variant="secondary" data-test="legal-preparing">
            <flux:callout.heading>{{ __('Dokument sa pripravuje') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Táto stránka zatiaľ nemá publikovanú verziu. Bezplatné funkcie aplikácie to neobmedzuje; platené predplatné sa spustí až s publikovanými podmienkami.') }} {!! __('Otázky: :link.', ['link' => '<a href="'.route('legal.contact').'" wire:navigate class="underline">'.__('kontakt').'</a>']) !!}</flux:callout.text>
        </flux:callout>
    @else
        @if ($preview)
            <flux:callout icon="exclamation-triangle" variant="warning" data-test="legal-draft-banner">
                <flux:callout.heading>{{ __('Pracovný návrh – nie je právne schválený ani publikovaný') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Vidíš ho, lebo si administrátor. Údaje v hranatých zátvorkách sú nevyplnené.') }}
                    @if ($document->hasPlaceholders())
                        {{ __('Chýba: :placeholders.', ['placeholders' => implode(', ', $document->placeholders())]) }}
                    @endif
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="legal-prose" data-test="legal-content">
            {!! $document->html() !!}
        </div>
    @endif

    @if ($type === LegalDocumentType::Cookies)
        <section class="space-y-4" data-test="cookies-inventory">
            <flux:heading size="lg" class="font-display">{{ __('Používané technológie a služby') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Zoznam sa generuje z registra služieb, ktorý prevádzkovateľ spravuje – uvádza iba skutočne nasadené technológie.') }}</flux:text>

            @foreach (ConsentCategory::cases() as $category)
                @php($rows = $this->services->get($category->value, collect()))
                @if ($rows->isEmpty())
                    @continue
                @endif
                <div class="space-y-2">
                    <flux:heading class="font-display">{{ $category->label() }}</flux:heading>
                    <flux:text class="text-sm">{{ $category->description() }}</flux:text>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-zinc-500"><tr><th class="py-1 pe-3">{{ __('Služba') }}</th><th class="py-1 pe-3">{{ __('Poskytovateľ') }}</th><th class="py-1 pe-3">{{ __('Účel') }}</th><th class="py-1 pe-3">{{ __('Cookies / úložisko') }}</th><th class="py-1">{{ __('Uchovanie a miesto') }}</th></tr></thead>
                            <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800">
                                @foreach ($rows as $service)
                                    <tr class="align-top">
                                        <td class="py-2 pe-3 font-medium">{{ $service->name }}</td>
                                        <td class="py-2 pe-3">{{ $service->provider }}</td>
                                        <td class="py-2 pe-3">{{ $service->purpose }}</td>
                                        <td class="py-2 pe-3">
                                            @forelse ($service->storage ?? [] as $entry)
                                                <div><code class="text-xs">{{ $entry['name'] ?? '' }}</code> <span class="text-xs text-zinc-500">({{ $entry['kind'] ?? 'cookie' }}, {{ $entry['domain'] ?? '' }}, {{ $entry['duration'] ?? '' }}) – {{ $entry['purpose'] ?? '' }}</span></div>
                                            @empty
                                                <span class="text-xs text-zinc-500">{{ __('bez cookies na našich stránkach') }}</span>
                                            @endforelse
                                        </td>
                                        <td class="py-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $service->retention }}@if ($service->location) · {{ $service->location }}@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            <flux:card class="space-y-2">
                <flux:heading class="font-display">{{ __('Vaša voľba') }}</flux:heading>
                @if (! $this->hasOptional)
                    <flux:text class="text-sm">{{ __('Momentálne nepoužívame žiadne voliteľné služby, preto nežiadame o súhlas. Ak nejakú pridáme, spýtame sa vopred.') }}</flux:text>
                @elseif ($this->decision)
                    <flux:text class="text-sm" data-test="consent-current">
                        {{ __('Uložená :date:', ['date' => \Carbon\CarbonImmutable::parse($this->decision['at'])->timezone(config('recipes.default_timezone'))->format('j. n. Y H:i')]) }}
                        @foreach ($this->decision['categories'] as $key => $on)
                            {{ ConsentCategory::from($key)->label() }} {{ $on ? __('zapnuté') : __('vypnuté') }}{{ $loop->last ? '' : ', ' }}
                        @endforeach
                    </flux:text>
                    <flux:button size="sm" data-consent-open>{{ __('Zmeniť alebo odvolať') }}</flux:button>
                @else
                    <flux:text class="text-sm">{{ __('Zatiaľ ste nerozhodli; voliteľné služby sú vypnuté.') }}</flux:text>
                    <flux:button size="sm" data-consent-open>{{ __('Nastavenia cookies') }}</flux:button>
                @endif
            </flux:card>
        </section>
    @endif

    @if ($type === LegalDocumentType::Withdrawal)
        <flux:card class="space-y-2" data-test="withdrawal-cta">
            <flux:heading class="font-display">{{ __('Odstúpiť online') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Formulár funguje aj bez prihlásenia. Prijatie potvrdíme ihneď e-mailom; refundácia nasleduje po posúdení. Nie je potrebné telefonovať.') }}</flux:text>
            <flux:button :href="route('legal.withdrawal.form')" wire:navigate variant="primary" size="sm">{{ __('Formulár odstúpenia od zmluvy') }}</flux:button>
        </flux:card>
    @endif

    @if ($this->operator->get('business_name') !== '' && $type !== LegalDocumentType::Cookies)
        <flux:text class="text-xs">{{ __(':operator. Kontakt: :email.', ['operator' => $this->operator->identityLine(), 'email' => $this->operator->get('support_email')]) }}</flux:text>
    @endif
</article>
