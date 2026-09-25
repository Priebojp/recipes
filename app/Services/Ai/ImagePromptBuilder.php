<?php

namespace App\Services\Ai;

use App\Enums\ServingMode;
use App\Enums\SideRequirement;

/**
 * Pure builder of the food photo prompt, including the vessel decision table from chapter 10.
 */
class ImagePromptBuilder
{
    /**
     * @param  array<string, mixed>  $recipe  snapshot: title, description, ingredients, steps, side_requirement, included_side, serving_mode
     * @return array{needs_description: bool, serving_mode: string, summary: string, prompt: string|null, auto_suggested: bool}
     */
    public function build(array $recipe, ?string $confirmedDescription = null, ?string $chosenMode = null): array
    {
        $title = trim((string) ($recipe['title'] ?? ''));
        $description = trim((string) ($confirmedDescription ?? $recipe['description'] ?? ''));
        $ingredients = array_values(array_filter(array_map(fn ($l) => trim((string) ($l['name'] ?? '')), $recipe['ingredients'] ?? [])));
        $steps = array_values(array_filter(array_map(fn ($s) => trim((string) ($s['text'] ?? '')), $recipe['steps'] ?? [])));
        $side = SideRequirement::tryFrom((string) ($recipe['side_requirement'] ?? 'unknown')) ?? SideRequirement::Unknown;
        $includedSide = trim((string) ($recipe['included_side'] ?? ''));
        $storedMode = ServingMode::tryFrom((string) ($recipe['serving_mode'] ?? 'auto')) ?? ServingMode::Auto;
        $userMode = $chosenMode !== null ? ServingMode::tryFrom($chosenMode) : null;

        // Title only, nothing else: ask for a short description instead of guessing the dish.
        if ($description === '' && $ingredients === [] && $steps === []) {
            return [
                'needs_description' => true,
                'serving_mode' => ($userMode ?? $storedMode)->value,
                'summary' => 'Názov „'.$title.'“ sám o sebe nestačí. Doplň krátky opis, čo to je za jedlo.',
                'prompt' => null,
                'auto_suggested' => false,
            ];
        }

        $method = implode(' ', $steps).' '.$description;
        $auto = false;

        $mode = $userMode !== null && $userMode !== ServingMode::Auto ? $userMode : ($storedMode !== ServingMode::Auto ? $storedMode : null);

        if ($mode === null) {
            $auto = true;
            $mode = match ($side) {
                SideRequirement::Complete => $this->looksLikeSoup($title.' '.$description) ? ServingMode::Bowl : ServingMode::Plate,
                SideRequirement::NeedsSide => $this->vesselFromMethod($method),
                SideRequirement::Unknown => $this->looksLikeSoup($title.' '.$description) ? ServingMode::Bowl : ServingMode::Plate,
            };
        }

        $sideText = $side === SideRequirement::NeedsSide
            ? 'bez prílohy'
            : ($includedSide !== '' ? 'príloha: '.$includedSide : ($side === SideRequirement::Complete ? 'kompletné jedlo' : 'príloha neurčená'));

        $summary = trim(($description !== '' ? $description : $title).' '.$mode->promptText().', '.$sideText);

        $prompt = implode("\n", [
            'Vytvor fotorealistickú fotografiu skutočného domáceho jedla.',
            'Názov: '.$title,
            'Opis potvrdený používateľom: '.($description !== '' ? $description : 'neznámy'),
            'Známe suroviny: '.($ingredients !== [] ? implode(', ', $ingredients) : 'neznáme'),
            'Relevantný spôsob prípravy: '.($steps !== [] ? mb_substr(implode(' ', $steps), 0, 600) : 'neznámy'),
            'Potvrdené servírovanie: '.$mode->promptText(),
            'Samostatná príloha, iba ak je explicitne súčasťou receptu: '.($side !== SideRequirement::NeedsSide && $includedSide !== '' ? $includedSide : 'žiadna'),
            '',
            'Jedlo musí vzhľadom zodpovedať poskytnutým údajom. Nepridávaj',
            'neuvedenú samostatnú prílohu, ozdobu ani dominantnú surovinu.',
            'Ak je servírovanie v hrnci, kastróle alebo pekáči, zobraz iba jedlo',
            'v tejto nádobe, prirodzene po dovarení. Ak je to kompletné jedlo,',
            'zobraz bežnú domácu porciu na tanieri alebo v primeranej miske.',
            '',
            'Prirodzené svetlo pri okne, obyčajný riad, uveriteľná konzistencia,',
            'nepravidelné kúsky, jemné prirodzené nedokonalosti. Neutrálne domáce',
            'prostredie, záber z mierneho nadhľadu, jedlo je hlavný predmet.',
            'Bez reklamného food stylingu, plastového lesku, prehnanej saturácie,',
            'textu, loga, koláže, rúk a dekoratívnych surovín rozložených okolo.',
            'Kompozícia použiteľná na karte s pomerom strán 4:3.',
        ]);

        return [
            'needs_description' => false,
            'serving_mode' => $mode->value,
            'summary' => $summary,
            'prompt' => $prompt,
            'auto_suggested' => $auto,
        ];
    }

    private function looksLikeSoup(string $text): bool
    {
        return (bool) preg_match('/polievk|vývar|krém(?:ová)?\s+poliev|guláš(?:ová)? poliev|boršč|kapustnic/iu', $text);
    }

    private function vesselFromMethod(string $method): ServingMode
    {
        if (preg_match('/pekáč|pečieme|upeč|rúr[ae]|zapeč|zapekáme/iu', $method)) {
            return ServingMode::BakingDish;
        }
        if (preg_match('/kastról|panvic|restuj|opraž|dusíme|duste|podus/iu', $method)) {
            return ServingMode::Casserole;
        }

        return ServingMode::Pot;
    }
}
