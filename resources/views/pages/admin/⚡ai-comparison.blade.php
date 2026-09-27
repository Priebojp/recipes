<?php

use App\Enums\AiJobStatus;
use App\Services\Ai\ImageProfile;
use App\Services\Ai\ImageProfileComparison;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Side-by-side rating of one low/medium comparison run (v2.1 stage 8). The administrator marks each image acceptable
 * or not (dish and side correct, natural look, artefacts) and records the decision as the launch sign-off; the offer
 * itself changes only in stage 13.
 */
new #[Layout('layouts::admin')] #[Title('Porovnanie profilov obrázkov')] class extends Component {
    public string $run = '';

    /** @var array<int, string> job id => yes|no|'' */
    public array $verdicts = [];

    /** @var array<int, string> */
    public array $notes = [];

    public string $decision = '';

    public string $decision_note = '';

    public function mount(string $run): void
    {
        $record = app(ImageProfileComparison::class)->record($run);
        abort_if($record === null, 404);

        $this->run = $run;
        foreach ($record['evaluations'] ?? [] as $jobId => $evaluation) {
            $this->verdicts[(int) $jobId] = match ($evaluation['acceptable'] ?? null) {
                true => 'yes',
                false => 'no',
                default => '',
            };
            $this->notes[(int) $jobId] = (string) ($evaluation['note'] ?? '');
        }
        $this->decision = (string) ($record['decision']['profile'] ?? '');
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return app(ImageProfileComparison::class)->summarize($this->run);
    }

    public function rate(int $jobId): void
    {
        $this->authorize('platform-admin');

        $acceptable = match ($this->verdicts[$jobId] ?? '') {
            'yes' => true,
            'no' => false,
            default => null,
        };

        try {
            app(ImageProfileComparison::class)->evaluate($this->run, $jobId, $acceptable, (string) ($this->notes[$jobId] ?? ''), auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('verdicts.'.$jobId, $e->getMessage());

            return;
        }

        unset($this->summary);
    }

    public function decide(): void
    {
        $this->authorize('platform-admin');

        $validated = $this->validate([
            'decision' => ['required', 'in:'.implode(',', array_map(fn (ImageProfile $p) => $p->value, ImageProfileComparison::PROFILES))],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        app(ImageProfileComparison::class)->decide($this->run, ImageProfile::from($validated['decision']), (string) ($validated['decision_note'] ?? ''), auth()->user());

        $this->decision_note = '';
        unset($this->summary);
        Flux::toast(variant: 'success', text: __('Rozhodnutie zapísané ako launch potvrdenie „Porovnanie obrázkov low/medium“ (audit). Ponuka sa mení až v etape 13.'));
    }
}; ?>

<div class="space-y-6">
    @php($s = $this->summary)
    @php($tz = config('recipes.default_timezone'))
    <x-page-header :title="__('Porovnanie profilov obrázkov')" :subtitle="__('Beh :run', ['run' => $run]).' · '.\Carbon\CarbonImmutable::parse($s['record']['at'])->timezone($tz)->format('j. n. Y H:i').' · '.__('domácnosť #:id', ['id' => $s['record']['household_id']]).' · '.__('model :model', ['model' => $s['record']['model'] ?? __('predvolený')]).' · '.__('spolu :cost', ['cost' => Money::microUsd((int) $s['total_cost_micro'])]).'. '.__('Hodnotí administrátor: správnosť jedla a prílohy, prirodzenosť, artefakty, použiteľnosť na karte.').' '.__('Malá vzorka rozhoduje, nedokazuje presnosť.')" :back="route('admin.ai')" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="comparison-summary">
        @foreach (ImageProfileComparison::PROFILES as $profile)
            @php($p = $s['profiles'][$profile->value])
            <flux:card class="space-y-1">
                <flux:text class="text-xs uppercase tracking-wide">{{ $profile->label() }} · {{ $profile->quality() }} · {{ $profile->pixelSize() }}</flux:text>
                <flux:heading size="xl" class="font-display">{{ $p['acceptable'] }} / {{ $p['evaluated'] }} <span class="text-base font-normal text-zinc-500">{{ __('prijateľných / hodnotených') }}</span></flux:heading>
                <flux:text class="text-xs">{{ __(':succeeded/:jobs doručených', ['succeeded' => $p['succeeded'], 'jobs' => $p['jobs']]) }} @if ($p['failed']) · <span class="text-red-600">{{ __(':count chýb', ['count' => $p['failed']]) }}</span> @endif · {{ __(':cost spolu', ['cost' => Money::microUsd($p['cost_micro'])]) }} · Ø {{ Money::microUsd($p['avg_cost_micro']) }}</flux:text>
            </flux:card>
        @endforeach
        <flux:card class="space-y-1">
            <flux:text class="text-xs uppercase tracking-wide">{{ __('Kritérium ≥ :minimum / :total Economy', ['minimum' => ImageProfileComparison::ECONOMY_ACCEPTABLE_MINIMUM, 'total' => ImageProfileComparison::imagesPerProfile()]) }}</flux:text>
            @php($c = $s['criterion_met'])
            <flux:heading size="xl" class="font-display {{ $c === true ? 'text-green-700 dark:text-green-400' : ($c === false ? 'text-red-600' : '') }}">{{ $c === true ? __('splnené') : ($c === false ? __('nesplnené') : __('ohodnoť všetky Economy')) }}</flux:heading>
            <flux:text class="text-xs">{{ __('Plus podmienka: žiadna systematická zámena jedla alebo prílohy – posúď v poznámkach.') }}</flux:text>
        </flux:card>
    </div>

    @foreach ($s['dishes'] as $dish)
        <flux:card class="space-y-3" data-test="dish-{{ $loop->index }}">
            <flux:heading size="lg" class="font-display">{{ $dish['title'] }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (ImageProfileComparison::PROFILES as $profile)
                    @foreach ($dish['jobs'][$profile->value] ?? [] as $job)
                        <div class="space-y-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-800" data-test="job-{{ $job->id }}">
                            <div class="flex items-center justify-between text-xs">
                                <flux:badge size="sm" :color="$profile === ImageProfile::EconomyV1 ? 'amber' : 'blue'">{{ $profile->label() }} · {{ $job->profile['quality'] ?? $profile->quality() }}</flux:badge>
                                <span class="text-zinc-500">#{{ $job->id }} · {{ Money::microUsd($job->estimated_cost_micro_usd) }}</span>
                            </div>
                            @if ($job->status === AiJobStatus::Succeeded && $job->result_media_id)
                                <img src="{{ route('admin.ai.comparison.media', [$run, $job->result_media_id, 'card']) }}" alt="{{ $dish['title'] }} – {{ $profile->label() }}" class="aspect-square w-full rounded object-cover" loading="lazy" />
                            @else
                                <div class="flex aspect-square items-center justify-center rounded bg-zinc-100 p-3 text-center text-xs text-red-600 dark:bg-zinc-800">{{ $job->status->value }}{{ $job->error ? ': '.\Illuminate\Support\Str::limit($job->error, 120) : '' }}</div>
                            @endif
                            <flux:select wire:model="verdicts.{{ $job->id }}" wire:change="rate({{ $job->id }})" size="sm" data-test="verdict-{{ $job->id }}">
                                <flux:select.option value="">{{ __('– nehodnotené –') }}</flux:select.option>
                                <flux:select.option value="yes">{{ __('prijateľný: áno') }}</flux:select.option>
                                <flux:select.option value="no">{{ __('prijateľný: nie') }}</flux:select.option>
                            </flux:select>
                            <flux:input wire:model="notes.{{ $job->id }}" wire:change="rate({{ $job->id }})" size="sm" :placeholder="__('poznámka (jedlo/príloha, prirodzenosť, artefakty)')" />
                            @error('verdicts.'.$job->id) <flux:text class="text-xs text-red-600">{{ $message }}</flux:text> @enderror
                        </div>
                    @endforeach
                @endforeach
            </div>
        </flux:card>
    @endforeach

    <flux:card class="space-y-3" data-test="decision">
        <flux:heading size="lg" class="font-display">{{ __('Rozhodnutie') }}</flux:heading>
        @if ($s['record']['decision'] ?? null)
            <flux:callout icon="check-circle" variant="success">
                <flux:callout.text>{{ __('Zapísané :date: profil :profile:note.', ['date' => \Carbon\CarbonImmutable::parse($s['record']['decision']['at'])->timezone($tz)->format('j. n. Y H:i'), 'profile' => ImageProfile::tryFrom($s['record']['decision']['profile'])?->label() ?? $s['record']['decision']['profile'], 'note' => $s['record']['decision']['note'] !== '' ? ' · '.$s['record']['decision']['note'] : '']) }} {{ __('Nová verzia ponuky sa zakladá až v etape 13 (/admin/catalog); zaplatené Standard balíky sa nikdy nemenia na Economy.') }}</flux:callout.text>
            </flux:callout>
        @endif
        <form wire:submit="decide" class="grid gap-3 sm:grid-cols-[auto_1fr_auto] sm:items-end">
            <flux:select wire:model="decision" :label="__('Profil pre novú ponuku')">
                <flux:select.option value="">{{ __('– vyber –') }}</flux:select.option>
                @foreach (ImageProfileComparison::PROFILES as $profile)
                    <flux:select.option :value="$profile->value">{{ $profile->label() }} ({{ $profile->value }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="decision_note" :label="__('Poznámka (systematické zámeny, výhrady)')" />
            <flux:button type="submit" variant="primary">{{ __('Zapísať rozhodnutie') }}</flux:button>
        </form>
        <flux:text class="text-xs">{!! __('Rozhodnutie sa uloží ako launch potvrdenie :key (kto, kedy, výsledok) a do auditu.', ['key' => '<code>image_profile</code>']) !!} {{ __('Nemení ceny, limity ani existujúce nároky.') }}</flux:text>
    </flux:card>
</div>
