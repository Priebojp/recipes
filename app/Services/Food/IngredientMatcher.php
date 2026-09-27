<?php

namespace App\Services\Food;

use App\Enums\FoodGramsOrigin;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;
use App\Models\IngredientLine;
use Illuminate\Support\Collection;

/**
 * Proposes foods for an ingredient line from the curated dictionary (and optionally the provider's search) and
 * turns the line's amount into grams – but only through a mass unit or a confirmed per-food conversion.
 * It never invents an ID: every candidate is a stored record, every search hit is verified on import.
 */
class IngredientMatcher
{
    public function __construct(private FoodCatalog $catalog) {}

    public function propose(IngredientLine $line, bool $searchSource = false): MatchProposal
    {
        $candidates = $this->dictionaryCandidates($line);

        $hits = [];
        $error = null;
        if ($searchSource && $candidates === []) {
            try {
                foreach ($this->catalog->search($this->cleanName($line->name), 8) as $result) {
                    if ($result['record'] === null) {
                        $hits[] = $result['hit'];
                    } else {
                        $candidates[] = $this->candidate($line, $result['record'], null, 1);
                    }
                }
            } catch (FoodSourceUnavailableException $e) {
                $error = $e->getMessage();
            }
        }

        return new MatchProposal($candidates, $hits, $error);
    }

    /**
     * Dictionary candidates for a bare name (a component recognised on a photo, v2.1 stage 11). The amount is
     * already in grams or unknown, so no unit conversion takes part; the search hits of the provider are not used.
     */
    public function proposeName(string $name, ?float $grams = null): MatchProposal
    {
        $line = new IngredientLine(['name' => $name, 'numeric_amount' => $grams, 'unit' => $grams === null ? null : 'g']);

        return $this->propose($line);
    }

    /**
     * Grams the line stands for with this food. Mass units convert directly; volumes need the food's density row;
     * pieces and spoons need the food's own conversion. "Podľa chuti" and unknown units are unresolved.
     *
     * @return array{grams: float|null, origin: FoodGramsOrigin|null, conversion: FoodUnitConversion|null, reason: string|null}
     */
    public function resolveGrams(IngredientLine $line, FoodSourceRecord $record): array
    {
        $none = fn (string $reason) => ['grams' => null, 'origin' => null, 'conversion' => null, 'reason' => $reason];

        if ($line->numeric_amount === null) {
            $text = trim((string) $line->text_amount);

            return $none($text === '' ? 'Bez množstva – zadaj gramáž.' : 'Množstvo „'.$text.'“ nemá číselnú hodnotu – zadaj gramáž.');
        }

        $amount = (float) $line->numeric_amount;
        if ($amount <= 0) {
            return $none('Množstvo musí byť väčšie ako nula.');
        }

        $canonical = FoodUnit::canonical($line->unit);
        if ($canonical === null) {
            return $none('Neznáma jednotka „'.trim((string) $line->unit).'“ – zadaj gramáž.');
        }

        $grams = FoodUnit::massToGrams($amount, $canonical);
        if ($grams !== null) {
            return ['grams' => round($grams, 2), 'origin' => FoodGramsOrigin::UnitConversion, 'conversion' => null, 'reason' => null];
        }

        $conversionUnit = FoodUnit::conversionUnitFor($canonical);
        $conversion = $record->conversions->firstWhere('unit', $conversionUnit);
        if ($conversion === null) {
            $what = FoodUnit::isVolume($canonical) ? 'hustota (g na 1 ml)' : 'prevod jednotky „'.$canonical.'“ na gramy';

            return $none('Pre potravinu „'.$record->displayName().'“ nie je potvrdený '.$what.'.');
        }

        $units = FoodUnit::isVolume($canonical) ? FoodUnit::volumeToMillilitres($amount, $canonical) : $amount;

        return [
            'grams' => round((float) $units * (float) $conversion->grams, 2),
            'origin' => FoodGramsOrigin::UnitConversion,
            'conversion' => $conversion,
            'reason' => null,
        ];
    }

    /**
     * @return list<FoodCandidate>
     */
    private function dictionaryCandidates(IngredientLine $line): array
    {
        $normalized = FoodAlias::normalize($this->cleanName($line->name));
        if ($normalized === '') {
            return [];
        }

        $phrases = $this->phrases($normalized);

        /** @var Collection<int, FoodAlias> $aliases */
        $aliases = FoodAlias::query()
            ->with(['record.conversions'])
            ->whereIn('normalized', $phrases)
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($aliases as $alias) {
            // The whole name beats any part of it; a longer part ("hladká múka") beats a shorter one ("múka").
            $score = $alias->normalized === $normalized ? 10 : count(explode(' ', $alias->normalized));
            $existing = $candidates[$alias->food_source_record_id] ?? null;
            if ($existing !== null && $existing->score >= $score) {
                continue;
            }
            $candidates[$alias->food_source_record_id] = $this->candidate($line, $alias->record, $alias, $score);
        }

        $list = array_values($candidates);
        usort($list, fn (FoodCandidate $a, FoodCandidate $b) => [$b->score, $a->record->id] <=> [$a->score, $b->record->id]);

        return $list;
    }

    /**
     * The normalised name and every run of up to four consecutive words in it ("hladká múka na zahustenie" →
     * "hladká múka", "múka", …), so a multi-word alias can match a longer written name through the index.
     *
     * @return list<string>
     */
    private function phrases(string $normalized): array
    {
        $words = explode(' ', $normalized);
        $phrases = [$normalized];
        $count = count($words);
        for ($length = min(4, $count); $length >= 1; $length--) {
            for ($start = 0; $start + $length <= $count; $start++) {
                $phrase = implode(' ', array_slice($words, $start, $length));
                if (mb_strlen($phrase) >= 3) {
                    $phrases[] = $phrase;
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    private function candidate(IngredientLine $line, FoodSourceRecord $record, ?FoodAlias $alias, int $score): FoodCandidate
    {
        $record->loadMissing('conversions');
        $resolved = $this->resolveGrams($line, $record);

        return new FoodCandidate(
            record: $record,
            preparationState: $alias->preparation_state ?? $record->preparation_state,
            grams: $resolved['grams'],
            gramsOrigin: $resolved['origin'],
            conversion: $resolved['conversion'],
            unresolvedReason: $resolved['reason'],
            matchedAlias: $alias?->alias,
            score: $score,
        );
    }

    /**
     * "cibuľa (nakrájaná)" → "cibuľa"; a note in brackets never takes part in the match.
     */
    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s*[\(\[].*?[\)\]]/u', '', $name));
    }
}
