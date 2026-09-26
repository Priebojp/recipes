<?php

namespace App\Services\Billing;

use RuntimeException;

/** A purchase cannot start; the message is safe to show to the household owner. */
class CheckoutException extends RuntimeException {}
