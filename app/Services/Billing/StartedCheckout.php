<?php

namespace App\Services\Billing;

use App\Models\Order;

final readonly class StartedCheckout
{
    public function __construct(public Order $order, public string $url) {}
}
