<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiTextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
