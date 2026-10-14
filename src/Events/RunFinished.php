<?php

namespace Tey\Docket\Events;

use Illuminate\Queue\SerializesModels;
use Tey\Docket\Run;

/** @api */
class RunFinished
{
    use SerializesModels;

    public readonly string $id;

    public function __construct(public Run $run)
    {
        $this->id = $run->id.':finished';
    }
}
