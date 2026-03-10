<?php

namespace MakeDev\Orca\Enums;

enum OrcaSessionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingInput = 'awaiting_input';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PoppedOut = 'popped_out';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::AwaitingInput, self::PoppedOut]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled]);
    }
}
