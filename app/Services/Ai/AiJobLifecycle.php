<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Usage\InsufficientUsageException;
use App\Services\Usage\UsageLedger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * State transitions of an AI job tied to the usage ledger (specification 4 – transactional accounting):
 * create + reserve atomically, consume on delivered result, release on definitive failure, hold on ambiguity.
 */
class AiJobLifecycle
{
    public function __construct(private UsageLedger $ledger, private AdminAuditor $audit) {}

    /**
     * Create the queued job and reserve its use in one transaction. Nothing is created when no use is available.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientUsageException
     */
    public function create(array $attributes, AiJobKind $kind): AiJob
    {
        return DB::transaction(function () use ($attributes, $kind) {
            $job = AiJob::create($attributes);

            if ($this->ledger->enforced()) {
                $this->ledger->reserve($job, UsageKind::fromAiJobKind($kind));
            }

            return $job;
        });
    }

    /** Move a queued job to running; false when another worker already took it. */
    public function start(AiJob $job): bool
    {
        $updated = AiJob::query()
            ->whereKey($job->id)
            ->where('status', AiJobStatus::Queued)
            ->update(['status' => AiJobStatus::Running, 'started_at' => now(), 'updated_at' => now()]);

        if ($updated === 1) {
            $job->refresh();
        }

        return $updated === 1;
    }

    /**
     * The result is stored and reachable: mark delivered and consume the use atomically (application level).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function succeed(AiJob $job, array $attributes): void
    {
        DB::transaction(function () use ($job, $attributes) {
            $job->update(['status' => AiJobStatus::Succeeded, 'finished_at' => now(), 'error' => null, ...$attributes]);
            $this->ledger->consume($job);
        });
    }

    /**
     * Failure: definitive errors release the use once; an ambiguous timeout keeps it and waits for a decision.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fail(AiJob $job, Throwable $error, array $attributes = []): void
    {
        $message = mb_substr($error->getMessage(), 0, 1000);

        if ($this->isAmbiguous($error)) {
            $this->markReconciling($job, $message, $attributes);

            return;
        }

        DB::transaction(function () use ($job, $message, $attributes) {
            $job->update(['status' => AiJobStatus::Failed, 'error' => $message, 'finished_at' => now(), ...$attributes]);
            $this->ledger->release($job);
        });
    }

    /**
     * The provider call may or may not have produced a result: hold the reservation instead of paying twice.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markReconciling(AiJob $job, string $error, array $attributes = []): void
    {
        $job->update(['status' => AiJobStatus::Reconciling, 'error' => mb_substr($error, 0, 1000), 'finished_at' => now(), ...$attributes]);
    }

    /**
     * Operator decision for a reconciling job: no result can be recovered, so it fails and the use is returned.
     * The external cost (if any) stays with the operator. Audited.
     */
    public function resolveAsFailed(AiJob $job, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($job, $by, $reason) {
            $job->update([
                'status' => AiJobStatus::Failed,
                'error' => mb_substr(trim(($job->error ?? '').' Rozhodnutie: '.$reason), 0, 1000),
                'finished_at' => now(),
            ]);
            $this->ledger->release($job);
            $this->audit->record('ai.job.reconciled_failed', $job, ['status' => AiJobStatus::Reconciling->value], ['status' => AiJobStatus::Failed->value], $reason, $by);
        });
    }

    /**
     * Only outcomes the provider may have completed are ambiguous: a request that timed out after being sent,
     * or a worker killed mid-call. Refusals, validation errors and refused connections are definitive.
     */
    public function isAmbiguous(Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof TimeoutExceededException || $e instanceof MaxAttemptsExceededException) {
                return true;
            }

            $transport = $e instanceof ProviderConnectionException
                || $e instanceof ConnectionException
                || $e instanceof NetworkExceptionInterface;

            if ($transport && str_contains(mb_strtolower($e->getMessage()), 'timed out')) {
                return true;
            }
            if ($transport && $e->getPrevious() === null && str_contains(mb_strtolower($e->getMessage()), 'timeout')) {
                return true;
            }
        }

        return false;
    }
}
