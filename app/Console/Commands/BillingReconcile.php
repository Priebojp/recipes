<?php

namespace App\Console\Commands;

use App\Services\Admin\AppSettings;
use App\Services\Billing\BillingReconciler;
use App\Services\Launch\LaunchReadiness;
use Illuminate\Console\Command;

class BillingReconcile extends Command
{
    protected $signature = 'app:billing-reconcile';

    protected $description = 'Otvorí aktuálne mesačné AI granty, uzavrie opustené objednávky a zopakuje zlyhané Stripe udalosti.';

    public function handle(BillingReconciler $reconciler, AppSettings $settings): int
    {
        $result = $reconciler->run();
        // The launch checklist reads this to tell whether the scheduler is alive.
        $settings->set(LaunchReadiness::RECONCILE_KEY, now()->toIso8601String());

        $this->info("Otvorené granty: {$result['grants_opened']}, uzavreté objednávky: {$result['orders_expired']}, zopakované udalosti: {$result['events_retried']} (stále zlyhané: {$result['events_failed']}).");

        return $result['events_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
