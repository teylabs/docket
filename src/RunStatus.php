<?php

namespace Tey\Docket;

/** @api */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }
}
