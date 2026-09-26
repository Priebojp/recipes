<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Models\Household;
use App\Services\Ai\ImageProfile;
use App\Services\Ai\ImageProfileComparison;
use App\Support\Money;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Runs the low/medium image comparison (v2.1 stage 8): 10 dishes × (2 Economy + 2 Standard) on the real provider key,
 * with the list-price estimate shown before anything is paid. The rating happens in /admin/ai/comparisons/{run}.
 */
class AiCompareImages extends Command
{
    protected $signature = 'app:ai-compare-images
        {household? : ID testovacej domácnosti prevádzkovateľa, do ktorej sa uložia recepty jedál a obrázky}
        {--report= : Iba vypísať súhrn skoršieho behu (kľúč behu)}
        {--yes : Potvrdiť, že porovnanie volá platené API poskytovateľa}';

    protected $description = 'Porovnanie profilov obrázkov Economy (low) a Standard (medium): 10 jedál × 4 obrázky na reálnom kľúči (etapa 8).';

    public function handle(ImageProfileComparison $comparison): int
    {
        if ($this->option('report')) {
            $this->report($comparison->summarize((string) $this->option('report')));

            return self::SUCCESS;
        }

        $household = Household::find((int) $this->argument('household'));
        if ($household === null) {
            $this->components->error('Zadaj ID existujúcej (testovacej) domácnosti.');

            return self::INVALID;
        }

        $estimate = $comparison->estimate();
        $perProfile = ImageProfileComparison::imagesPerProfile();
        $this->components->info('Odhad nákladu z cenníka (ceny poskytovateľa, bez vstupov a daní) · model '.($estimate['model'] ?? 'predvolený'));
        foreach (ImageProfileComparison::PROFILES as $profile) {
            $micro = $estimate['profiles'][$profile->value];
            $this->components->twoColumnDetail("{$perProfile} × {$profile->label()} ({$profile->quality()} {$profile->pixelSize()})", $micro !== null ? Money::microUsd($micro, 2) : 'bez sadzby v cenníku');
        }
        $this->components->twoColumnDetail('Spolu', $estimate['total'] !== null ? Money::microUsd($estimate['total'], 2) : 'nedá sa – doplň sadzby (AiCostRateSeeder / Cenník AI)');
        $this->line('<fg=gray>Recepty jedál sa vytvoria v domácnosti #'.$household->id.' „'.$household->name.'“; obrázky sa uložia do ich neaktívnej cover kolekcie a nič sa neaktivuje.</>');

        $total = count(ImageProfileComparison::DISHES) * count(ImageProfileComparison::PROFILES) * ImageProfileComparison::VARIANTS;
        if (! $this->option('yes') && ! $this->confirm("Spustiť {$total} obrázkových úloh na reálnom kľúči? Volá to platené API.", false)) {
            $this->components->warn('Zrušené.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        try {
            $record = $comparison->run($household, null, function (AiJob $job) use ($bar) {
                $bar->advance();
            });
        } catch (InvalidArgumentException $e) {
            $bar->clear();
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $bar->finish();
        $this->newLine(2);
        $this->report($comparison->summarize($record['run']));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function report(array $summary): void
    {
        if ($summary['record'] === null) {
            $this->components->warn('Žiadny beh s týmto kľúčom.');

            return;
        }

        $this->components->info("Porovnanie {$summary['run']} · {$summary['record']['at']} · spolu ".Money::microUsd((int) $summary['total_cost_micro']));

        $rows = [];
        foreach ($summary['profiles'] as $code => $p) {
            $profile = ImageProfile::from($code);
            $rows[] = [
                $profile->label().' ('.$profile->quality().')',
                "{$p['succeeded']}/{$p['jobs']}".($p['failed'] > 0 ? " ({$p['failed']} chýb)" : ''),
                $p['avg_cost_micro'] !== null ? Money::microUsd($p['avg_cost_micro']) : '–',
                Money::microUsd((int) $p['cost_micro']),
                "{$p['acceptable']}/{$p['evaluated']}",
            ];
        }
        $this->table(['Profil', 'Doručené', 'Ø cena', 'Spolu', 'Prijateľné / hodnotené'], $rows);

        $this->line('<fg=gray>Hodnotenie (prijateľný áno/nie + poznámka) a rozhodnutie: /admin/ai/comparisons/'.$summary['run'].' – hodnotí administrátor, nie AI.</>');
    }
}
