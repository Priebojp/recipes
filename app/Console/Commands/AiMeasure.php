<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Models\Household;
use App\Services\Ai\AiMeasurement;
use App\Services\Ai\AiTextService;
use App\Services\Billing\Catalog;
use App\Support\Money;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Runs the launch measurement (30 text + 30 image jobs by default) on the real provider key and prints what one
 * operation costs, or re-prints the report of an earlier run.
 */
class AiMeasure extends Command
{
    protected $signature = 'app:ai-measure
        {household? : ID domácnosti s receptami, na ktorej sa meria (odporúčaná vlastná testovacia)}
        {--text=30 : Počet textových úloh}
        {--images=30 : Počet obrázkových úloh}
        {--meal-analyses=0 : Počet analýz jedla z testovacích fotiek (v2.1 etapa 11)}
        {--fixtures= : Priečinok s vlastnými fotkami jedál (predvolene tests/fixtures/meals)}
        {--scope=full : Rozsah textovej úpravy (description|steps|full)}
        {--report= : Iba vypísať report skoršieho behu (kľúč behu)}
        {--yes : Potvrdiť, že meranie volá platené API poskytovateľa}';

    protected $description = 'Zmeria skutočné náklady AI úloh na reálnom kľúči (etapa 7) a uloží výsledok pre launch checklist.';

    public function handle(AiMeasurement $measurement): int
    {
        if ($this->option('report')) {
            $this->report($measurement->summarize((string) $this->option('report')));

            return self::SUCCESS;
        }

        $household = Household::find((int) $this->argument('household'));
        if ($household === null) {
            $this->components->error('Zadaj ID existujúcej domácnosti.');

            return self::INVALID;
        }

        $texts = max(0, (int) $this->option('text'));
        $images = max(0, (int) $this->option('images'));
        $meals = max(0, (int) $this->option('meal-analyses'));
        $fixtures = $this->option('fixtures') ? (string) $this->option('fixtures') : null;
        $scope = (string) $this->option('scope');
        if (! in_array($scope, AiTextService::SCOPES, true)) {
            $this->components->error('Rozsah musí byť description, steps alebo full.');

            return self::INVALID;
        }

        $what = "{$texts} textových a {$images} obrázkových úloh".($meals > 0 ? " a {$meals} analýz jedla" : '');
        if (! $this->option('yes') && ! $this->confirm("Spustiť {$what} na reálnom kľúči pre domácnosť #{$household->id} „{$household->name}“? Volá to platené API.", false)) {
            $this->components->warn('Zrušené.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($texts + $images + $meals);
        $bar->start();

        try {
            $summary = $measurement->run($household, $texts, $images, $scope, function (AiJob $job) use ($bar) {
                $bar->advance();
            }, null, $meals, $fixtures);
        } catch (InvalidArgumentException $e) {
            $bar->clear();
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $bar->finish();
        $this->newLine(2);
        $this->report($summary);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function report(array $summary): void
    {
        $this->components->info("Meranie {$summary['run']} · {$summary['at']} · spolu ".Money::microUsd((int) $summary['total_cost_micro']));

        if ($summary['kinds'] === []) {
            $this->components->warn('Žiadne úlohy s týmto kľúčom behu.');

            return;
        }

        $rows = [];
        foreach ($summary['kinds'] as $kind => $k) {
            $profile = $k['profile'] ?? [];
            $rows[] = [
                $kind,
                ($k['model'] ?? '?').' '.implode(' ', array_filter([$profile['reasoning_effort'] ?? null, $profile['quality'] ?? null, $profile['size'] ?? null])),
                "{$k['succeeded']}/{$k['jobs']}".($k['failed'] > 0 ? " ({$k['failed']} chýb)" : '').($k['unpriced'] > 0 ? " · {$k['unpriced']} bez ceny" : ''),
                $k['avg_cost_micro'] !== null ? Money::microUsd($k['avg_cost_micro']) : '–',
                $k['min_cost_micro'] !== null ? Money::microUsd($k['min_cost_micro']).' – '.Money::microUsd($k['max_cost_micro']) : '–',
                Money::microUsd((int) $k['total_cost_micro']),
                $k['avg_duration_ms'] !== null ? round($k['avg_duration_ms'] / 1000, 1).' s' : '–',
                $kind === 'image'
                    ? ('img out '.($k['avg_image_output_tokens'] ?? '–'))
                    : (($k['avg_input_tokens'] ?? '–').' / '.($k['avg_output_tokens'] ?? '–').' (reasoning '.($k['avg_reasoning_tokens'] ?? '–').')'),
            ];
        }
        $this->table(['Druh', 'Model · profil', 'Doručené', 'Ø cena', 'Rozpätie', 'Spolu', 'Ø trvanie', 'Ø tokeny vstup / výstup'], $rows);

        foreach ($summary['kinds'] as $kind => $k) {
            foreach ($k['errors'] ?? [] as $error) {
                $this->line("  <fg=red>{$kind}:</> {$error}");
            }
        }

        if ($meal = $summary['kinds']['meal_analysis'] ?? null) {
            $this->newLine();
            $this->line('<options=bold>Analýzy jedla (celá analýza vrátane doplnení)</>');
            $this->components->twoColumnDetail('Doručené / spustené', "{$meal['delivered']} / {$meal['analyses']}".($meal['success_rate'] !== null ? ' ('.round($meal['success_rate'] * 100).' %)' : ''));
            $this->components->twoColumnDetail('Výsledky', implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($meal['outcomes']), $meal['outcomes'])));
            $this->components->twoColumnDetail('Cena medián / p95', ($meal['median_cost_micro'] !== null ? Money::microUsd($meal['median_cost_micro']) : '–').' / '.($meal['p95_cost_micro'] !== null ? Money::microUsd($meal['p95_cost_micro']) : '–'));
            $this->components->twoColumnDetail('Trvanie medián / p95', ($meal['median_duration_ms'] !== null ? round($meal['median_duration_ms'] / 1000, 1).' s' : '–').' / '.($meal['p95_duration_ms'] !== null ? round($meal['p95_duration_ms'] / 1000, 1).' s' : '–'));
            $this->components->twoColumnDetail('Otázky / opravy / doplnenia', "{$meal['questions_asked']} / {$meal['corrections']} / {$meal['clarifications']}");
        }

        $p = $summary['projection'];
        $rate = config('recipes.billing.usd_eur_rate');
        $eur = fn (?int $micro) => $micro !== null && $rate ? ' ≈ '.Catalog::formatCents((int) round($micro / 1_000_000 * $rate * 100)) : '';

        $this->newLine();
        $this->line('<options=bold>Projekcia (ceny poskytovateľa, bez poplatkov Stripe a daní)</>');
        $this->components->twoColumnDetail('Plný mesiac Plus (30 textov + 5 obrázkov) vs. 2,49 € / 2,00 €', $p['plus_month_micro'] !== null ? Money::microUsd($p['plus_month_micro'], 2).$eur($p['plus_month_micro']) : 'nedá sa – chýba priemer textu alebo obrázka');
        $this->components->twoColumnDetail('Balík 20 obrázkov vs. 3,99 €', $p['images_20_micro'] !== null ? Money::microUsd($p['images_20_micro'], 2).$eur($p['images_20_micro']) : '–');
        $this->components->twoColumnDetail('Balík 100 textov vs. 1,99 €', $p['text_100_micro'] !== null ? Money::microUsd($p['text_100_micro'], 2).$eur($p['text_100_micro']) : '–');
        if (! $rate) {
            $this->line('<fg=gray>Prepočet do EUR: nastav RECIPES_BILLING_USD_EUR_RATE.</>');
        }
        $this->line('<fg=gray>Výsledok je uložený pre launch checklist (/admin/launch). Ručné potvrdenie „Meranie AI vyhodnotené“ zostáva na prevádzkovateľovi.</>');
    }
}
