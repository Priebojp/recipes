<?php

namespace App\Console\Commands;

use App\Enums\LaunchCheckStatus;
use App\Services\Billing\Gateway\StripeInspector;
use App\Services\Launch\LaunchCheck as Check;
use App\Services\Launch\LaunchReadiness;
use Illuminate\Console\Command;

/**
 * Launch checklist for the deploy pipeline: exit code 1 while anything blocks the go-live.
 */
class LaunchCheck extends Command
{
    protected $signature = 'app:launch-check
        {--stripe : Overiť aj ceny a webhook endpoint priamo v Stripe účte (volá Stripe API)}
        {--json : Strojový výstup}';

    protected $description = 'Prejde launch checklist (prostredie, Stripe, katalóg, právne, admin, AI, ručné potvrdenia); vráti chybu, kým niečo blokuje.';

    public function handle(LaunchReadiness $readiness): int
    {
        $checks = $readiness->checks($this->option('stripe') ? app(StripeInspector::class) : null);
        $summary = $readiness->summary($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'checks' => array_map(fn (Check $c) => $c->toArray(), $checks)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $summary['ready'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($readiness->grouped($checks) as $group => $items) {
            $this->newLine();
            $this->components->twoColumnDetail("<options=bold>{$group}</>");
            foreach ($items as $check) {
                $this->components->twoColumnDetail($this->badge($check->status).' '.$check->label, (string) $check->detail);
                if ($check->hint !== null && $check->status !== LaunchCheckStatus::Ok) {
                    $this->line('      <fg=gray>→ '.$check->hint.'</>');
                }
            }
        }

        $this->newLine();
        $line = "OK {$summary['ok']} · upozornenia {$summary['warn']} · neoverené {$summary['skip']} · blokujúce {$summary['fail']}";
        if ($summary['ready']) {
            $this->components->info('Checklist bez blokujúcich položiek. '.$line);

            return self::SUCCESS;
        }

        $this->components->error('Launch je blokovaný. '.$line);

        return self::FAILURE;
    }

    private function badge(LaunchCheckStatus $status): string
    {
        return match ($status) {
            LaunchCheckStatus::Ok => '<fg=green>[ OK ]</>',
            LaunchCheckStatus::Warn => '<fg=yellow>[WARN]</>',
            LaunchCheckStatus::Fail => '<fg=red>[FAIL]</>',
            LaunchCheckStatus::Skip => '<fg=gray>[SKIP]</>',
        };
    }
}
