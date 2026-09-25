<div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" @if ($this->job && ! $this->job->status->isFinished()) wire:poll.3s="refreshStatus" @endif>
    <flux:text class="text-sm">AI opraví jazyk a štruktúru, nepridáva suroviny, množstvá, teploty ani kroky. Výsledok je iba návrh – originál sa nemení, kým ho neprijmeš.</flux:text>

    @if ($this->unavailable && ! $this->job)
        <flux:callout icon="information-circle" variant="secondary">{{ $this->unavailable }}</flux:callout>
    @endif

    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
        <flux:select wire:model="scope" label="Rozsah" class="sm:w-56">
            <flux:select.option value="description">Iba opis</flux:select.option>
            <flux:select.option value="steps">Iba postup</flux:select.option>
            <flux:select.option value="full">Celý zápis (aj voľný text)</flux:select.option>
        </flux:select>
        <flux:button wire:click="request" icon="sparkles" :disabled="(bool) $this->unavailable" data-test="ai-text-request">Upraviť text pomocou AI</flux:button>
    </div>

    @if ($error)
        <flux:callout icon="exclamation-circle" variant="danger">{{ $error }}</flux:callout>
    @endif
    @if ($notice)
        <flux:callout icon="check-circle" variant="success">{{ $notice }}</flux:callout>
    @endif

    @if ($job = $this->job)
        @if (! $job->status->isFinished())
            <div class="flex items-center gap-2 text-sm text-zinc-500"><flux:icon name="arrow-path" class="size-4 animate-spin" /> AI pracuje… recept môžeš ďalej používať.</div>
        @elseif ($job->status->value === 'failed')
            <flux:callout icon="exclamation-triangle" variant="warning">
                <flux:callout.heading>AI úprava zlyhala</flux:callout.heading>
                <flux:callout.text>{{ $job->error }} Tvoj text zostal nezmenený.</flux:callout.text>
                <x-slot name="actions"><flux:button size="sm" wire:click="request(true)">Skúsiť znova</flux:button></x-slot>
            </flux:callout>
        @elseif ($job->output)
            @php($output = $job->output)
            @php($recipe = $this->recipe)

            @if ($this->isStale)
                <flux:callout icon="exclamation-triangle" variant="warning">
                    Recept sa od spustenia AI zmenil. Návrh vychádza zo staršej verzie – porovnaj ho, ale automaticky sa už nepoužije. Spusti úpravu znova pre aktuálnu verziu.
                </flux:callout>
            @endif

            @if ($output['change_summary'] !== [])
                <div class="text-sm"><span class="font-medium">Zmeny:</span> {{ implode(' · ', $output['change_summary']) }}</div>
            @endif
            @if ($output['questions'] !== [])
                <flux:callout icon="question-mark-circle" variant="secondary">
                    <flux:callout.heading>Otázky AI (nie sú súčasťou receptu)</flux:callout.heading>
                    <flux:callout.text><ul class="list-disc pl-4">@foreach ($output['questions'] as $q)<li>{{ $q }}</li>@endforeach</ul></flux:callout.text>
                </flux:callout>
            @endif

            <div class="space-y-3">
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:checkbox wire:model="fields" value="title" label="Názov" />
                    <div class="mt-1 text-sm">{!! App\Support\TextDiff::html($recipe->title, $output['suggested_title']) !!}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:checkbox wire:model="fields" value="description" label="Opis" />
                    <div class="mt-1 whitespace-pre-line text-sm">{!! App\Support\TextDiff::html((string) $recipe->description, $output['suggested_description']) !!}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:checkbox wire:model="fields" value="ingredients" label="Suroviny ({{ count($output['ingredients']) }})" />
                    <div class="mt-1 grid gap-2 text-sm sm:grid-cols-2">
                        <div><div class="text-xs text-zinc-500">Pôvodné</div>@foreach ($recipe->ingredients as $l)<div>{{ $l->numeric_amount !== null ? rtrim(rtrim($l->numeric_amount, '0'), '.') : $l->text_amount }} {{ $l->unit }} {{ $l->name }}</div>@endforeach</div>
                        <div><div class="text-xs text-zinc-500">Návrh</div>@foreach ($output['ingredients'] as $l)<div>{{ $l['amount'] }} {{ $l['unit'] }} {{ $l['name'] }}@if($l['note']) <span class="text-zinc-500">({{ $l['note'] }})</span>@endif</div>@endforeach</div>
                    </div>
                </div>
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:checkbox wire:model="fields" value="steps" label="Postup ({{ count($output['steps']) }} krokov)" />
                    <div class="mt-1 space-y-2 text-sm">
                        @foreach ($output['steps'] as $i => $step)
                            @php($original = $step['source_id'] ? $recipe->steps->firstWhere('id', $step['source_id']) : null)
                            <div><span class="font-semibold">{{ $i + 1 }}.</span> {!! App\Support\TextDiff::html($original?->text ?? '', $step['text']) !!}</div>
                        @endforeach
                    </div>
                    @if ($this->photoSteps !== [])
                        <flux:callout icon="photo" variant="warning" class="mt-2">
                            <flux:callout.text>Kroky s fotografiami boli zlúčené alebo rozdelené. Fotografie krokov, ktoré návrh nezachoval, sa presunú na posledný krok.</flux:callout.text>
                            <flux:checkbox wire:model="confirmPhotos" label="Potvrdzujem priradenie fotografií" class="mt-2" />
                        </flux:callout>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="apply" :disabled="$this->isStale" data-test="ai-text-apply">Použiť vybrané</flux:button>
                <flux:button variant="ghost" wire:click="dismiss">Odmietnuť</flux:button>
                <flux:button variant="ghost" wire:click="request(true)">Vygenerovať znova</flux:button>
            </div>
        @endif
    @endif
</div>
