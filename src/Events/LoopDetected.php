<?php

namespace Tey\Docket\Events;

use Illuminate\Queue\SerializesModels;
use Tey\Docket\Run;

/** @api */
class LoopDetected
{
    use SerializesModels;

    public readonly string $id;

    public function __construct(public Run $run, public Run $repeats, public string $fingerprint)
    {
        $this->id = $run->id.':loop-detected';
    }
}
