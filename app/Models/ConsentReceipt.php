<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Proof of a cookie decision: pseudonymous visitor id, categories, policy version, action, time. Never an IP address.
 *
 * @property int $id
 * @property string $visitor_id
 * @property int|null $user_id
 * @property string $policy_version
 * @property int|null $cookies_document_version_id
 * @property string $action
 * @property array<string, bool> $categories
 * @property CarbonInterface $created_at
 */
#[Fillable(['visitor_id', 'user_id', 'policy_version', 'cookies_document_version_id', 'action', 'categories', 'created_at'])]
class ConsentReceipt extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['categories' => 'array', 'created_at' => 'datetime'];
    }
}
