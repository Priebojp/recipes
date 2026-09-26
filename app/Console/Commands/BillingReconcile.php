<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingReconciler;
use Illuminate\Console\Command;

class BillingReconcile extends Command
{
    protected $signature = 'app:billing-reconcile';

    protected $description = 'Otvorí aktuálne mesačné AI granty, uzavrie opustené objednávky a zopakuje zlyhané Stripe udalosti.';

    public function handle(BillingReconciler $reconciler): int
    {
        $result = $reconciler->run();

        $this->info("Otvorené granty: {$result['grants_opened']}, uzavreté objednávky: {$result['orders_expired']}, zopakované udalosti: {$result['events_retried']} (stále zlyhané: {$result['events_failed']}).");

        return $result['events_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
