<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateRecipeImageJob implements ShouldQueue
{
    use Queueable;

    // A single attempt: a retried paid generation must be a conscious user action.
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $aiJobId) {}

    public function handle(AiImageService $service): void
    {
        $job = AiJob::find($this->aiJobId);
        if ($job !== null) {
            $service->run($job);
        }
    }
}
