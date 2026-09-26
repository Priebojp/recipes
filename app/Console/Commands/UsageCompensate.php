<?php

namespace App\Console\Commands;

use App\Enums\UsageKind;
use App\Models\Household;
use App\Services\Admin\Compensations;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * CLI twin of the admin compensation form: a compensation grant is a separate, audited grant –
 * never an edit of an existing one and never a carry-over of an expired monthly use.
 */
class UsageCompensate extends Command
{
    protected $signature = 'app:usage-compensate
        {household : ID domácnosti}
        {kind : text|image_standard}
        {quantity : Počet použití}
        {--reason= : Dôvod (povinný, ide do auditu)}
        {--expires= : Voliteľná expirácia (dátum/čas)}
        {--key= : Idempotentný kľúč (napr. číslo tiketu); bez neho sa vygeneruje}';

    protected $description = 'Vystaví kompenzačný grant AI použití domácnosti a zapíše ho do auditu.';

    public function handle(Compensations $compensations): int
    {
        $household = Household::find((int) $this->argument('household'));
        if ($household === null) {
            $this->error('Domácnosť neexistuje.');

            return self::INVALID;
        }

        $kind = UsageKind::tryFrom((string) $this->argument('kind'));
        $quantity = (int) $this->argument('quantity');
        $reason = trim((string) $this->option('reason'));
        if ($kind === null || $quantity < 1 || $reason === '') {
            $this->error('Zadaj druh (text|image_standard), počet ≥ 1 a --reason.');

            return self::INVALID;
        }

        $expires = $this->option('expires') ? now()->parse((string) $this->option('expires')) : null;

        try {
            $grant = $compensations->grantUses($household, $kind, $quantity, $reason, $expires, $this->option('key') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if (! $grant->wasRecentlyCreated) {
            $this->warn("Grant s kľúčom {$grant->source_key} už existuje (#{$grant->id}); nič nové sa nevystavilo.");

            return self::SUCCESS;
        }

        $this->info("Kompenzácia #{$grant->id}: {$quantity} × {$kind->label()} pre domácnosť {$household->id}.");

        return self::SUCCESS;
    }
}
