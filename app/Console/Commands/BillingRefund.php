<?php

namespace App\Console\Commands;

use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Services\Billing\RefundService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Support tool until the admin module (stage 4). The administrator names the amount and how many unused units go
 * back; nothing is estimated from the amount.
 */
class BillingRefund extends Command
{
    protected $signature = 'app:billing-refund
        {order : ID objednávky}
        {amount : Suma v centoch}
        {--kind=goodwill : withdrawal|complaint|goodwill}
        {--reason= : Dôvod (povinný)}
        {--text=0 : Počet nevyužitých textových použití na odobratie}
        {--images=0 : Počet nevyužitých obrázkov na odobratie}
        {--revoke-plus : Odobrať aj zaplatené obdobie Plus tejto objednávky}
        {--key= : Idempotentný kľúč (napr. číslo tiketu)}';

    protected $description = 'Refunduje objednávku cez Stripe a odoberie zodpovedajúce nároky s auditom.';

    public function handle(RefundService $refunds): int
    {
        $order = Order::find((int) $this->argument('order'));
        $kind = RefundKind::tryFrom((string) $this->option('kind'));
        $reason = trim((string) $this->option('reason'));

        if ($order === null || $kind === null || in_array($kind, [RefundKind::Dispute, RefundKind::External], true) || $reason === '') {
            $this->error('Zadaj existujúcu objednávku, --kind (withdrawal|complaint|goodwill) a --reason.');

            return self::INVALID;
        }

        $units = array_filter(['text' => (int) $this->option('text'), 'image_standard' => (int) $this->option('images')]);

        try {
            $case = $refunds->request($order, $kind, (int) $this->argument('amount'), $reason, $units, (bool) $this->option('revoke-plus'), null, $this->option('key') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($case->status !== RefundStatus::Processed) {
            $this->error("Refundácia #{$case->id} zlyhala: {$case->error}");

            return self::FAILURE;
        }

        $this->info("Refundácia #{$case->id} ({$case->amount_cents} c, Stripe {$case->stripe_refund_id}) spracovaná; objednávka #{$order->id} je {$order->fresh()->status->label()}.");

        return self::SUCCESS;
    }
}
