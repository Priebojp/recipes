<?php

namespace App\Services\Food;

use RuntimeException;

/**
 * The provider could not be asked: no API key, hourly limit reached, network or provider error. The message is
 * safe to show to an administrator (no secrets, no raw payloads).
 */
class FoodSourceUnavailableException extends RuntimeException {}
