<?php

namespace App\Enums;

enum AiJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }
}
