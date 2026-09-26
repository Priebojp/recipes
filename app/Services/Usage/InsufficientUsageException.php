<?php

namespace App\Services\Usage;

use RuntimeException;

/**
 * The household has no available use of the requested kind: nothing was reserved, no job was created.
 */
class InsufficientUsageException extends RuntimeException {}
