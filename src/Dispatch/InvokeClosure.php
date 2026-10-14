<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\Run;

/** @internal */
class InvokeClosure implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(private string $runId, private SerializableClosure $callback) {}

    public function handle(): void
    {
        ($this->callback)(Run::findOrFail($this->runId));
    }
}
