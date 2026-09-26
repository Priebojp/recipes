<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiJobLifecycle;
use App\Services\Ai\AiTextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunRecipeTextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $aiJobId) {}

    public function handle(AiTextService $service): void
    {
        $job = AiJob::find($this->aiJobId);
        if ($job !== null) {
            $service->run($job);
        }
    }

    /**
     * The worker was killed (timeout) while the provider call was in flight: the outcome is unknown, so the job
     * waits for reconciliation instead of paying a second time or silently returning the use.
     */
    public function failed(?Throwable $exception): void
    {
        $job = AiJob::find($this->aiJobId);
        if ($job !== null && $job->status->isActive()) {
            app(AiJobLifecycle::class)->markReconciling($job, $exception?->getMessage() ?? 'Worker prekročil časový limit.');
        }
    }
}
