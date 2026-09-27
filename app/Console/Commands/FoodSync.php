<?php

namespace App\Console\Commands;

use App\Models\FoodSourceRecord;
use App\Services\Food\FoodCatalog;
use Illuminate\Console\Command;

/**
 * Refresh the stored copy of every provider food (values, snapshot, USDA portions). Idempotent; curated names,
 * manual conversions and ingredient mappings are never touched. Run after `db:seed --class=FoodAliasSeeder`.
 */
class FoodSync extends Command
{
    protected $signature = 'app:food-sync {--dry-run : Iba overiť voči zdroju, nič nezapisovať} {--only=* : Iba tieto externé ID}';

    protected $description = 'Stiahne / obnoví výživové hodnoty uložených potravín z USDA FoodData Central (etapa 9, idempotentné).';

    public function handle(FoodCatalog $catalog): int
    {
        if (! $catalog->isSourceConfigured()) {
            $this->components->error('USDA FoodData Central nie je nakonfigurované – chýba USDA_FDC_API_KEY. Uložený slovník funguje ďalej, nové hodnoty sa nestiahnu.');

            return self::FAILURE;
        }

        $only = array_values(array_filter(array_map(fn ($id) => trim((string) $id), (array) $this->option('only')), fn (string $id) => $id !== ''));
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(($dryRun ? 'Dry run: ' : '').'obnovujem záznamy zdroja '.$catalog->provider().($only !== [] ? ' ('.count($only).' vybraných)' : '').'…');

        $summary = $catalog->sync($dryRun, function (FoodSourceRecord $record, string $outcome): void {
            $line = $record->external_id.' · '.$record->displayName();
            match ($outcome) {
                'updated' => $this->components->twoColumnDetail($line, $this->option('dry-run') ? 'v poriadku' : 'obnovené'),
                'warning' => $this->components->twoColumnDetail($line, '<fg=yellow>upozornenie</>'),
                'missing' => $this->components->twoColumnDetail($line, '<fg=red>v zdroji chýba</>'),
                default => $this->components->twoColumnDetail($line, '<fg=red>zlyhalo</>'),
            };
        }, $only === [] ? null : $only);

        $this->newLine();
        $this->components->twoColumnDetail('Skontrolované', (string) $summary['checked']);
        $this->components->twoColumnDetail($dryRun ? 'Bez zmeny názvu' : 'Obnovené', (string) $summary['updated']);
        $this->components->twoColumnDetail('Upozornenia (názov v zdroji sa líši)', (string) $summary['warnings']);
        $this->components->twoColumnDetail('V zdroji chýbajú', (string) $summary['missing']);

        if (isset($summary['error'])) {
            $this->components->error('Zastavené: '.$summary['error']);

            return self::FAILURE;
        }

        if ($summary['warnings'] > 0 || $summary['missing'] > 0) {
            $this->components->warn('Skontroluj označené záznamy v /admin/food – hodnoty sa uložili, priradenia sa nemenili.');
        }

        return self::SUCCESS;
    }
}
