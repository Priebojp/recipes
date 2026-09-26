<?php

namespace App\Services\Admin;

use App\Models\AdminAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only administrator audit trail. Never logs secrets: keys named like a secret are redacted.
 */
class AdminAuditor
{
    private const REDACTED_KEYS = ['password', 'secret', 'token', 'key', 'api_key', 'recovery_codes', 'two_factor_secret'];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $action,
        Model|string|null $target = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?User $actor = null,
    ): AdminAudit {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        [$targetType, $targetId] = match (true) {
            $target instanceof Model => [$target->getMorphClass(), (string) $target->getKey()],
            is_string($target) => [$target, null],
            default => [null, null],
        };

        $changes = null;
        if ($before !== [] || $after !== []) {
            $changes = ['before' => $this->redact($before), 'after' => $this->redact($after)];
        }

        return AdminAudit::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $lower = mb_strtolower((string) $key);
            $sensitive = false;
            foreach (self::REDACTED_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $sensitive = true;
                    break;
                }
            }

            $result[$key] = match (true) {
                $sensitive => '[redacted]',
                is_array($value) => $this->redact($value),
                default => $value,
            };
        }

        return $result;
    }
}
