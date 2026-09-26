<?php

namespace App\Enums;

enum AiJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** The provider call ended ambiguously (timeout, killed worker): the result is unknown and the reserved use is held until resolved. */
    case Reconciling = 'reconciling';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    /** Still being worked on (worth polling). */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'vo fronte',
            self::Running => 'beží',
            self::Succeeded => 'doručené',
            self::Failed => 'chyba',
            self::Reconciling => 'overuje sa',
        };
    }
}
