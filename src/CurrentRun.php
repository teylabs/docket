<?php

namespace Tey\Docket;

use Closure;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use WeakMap;

/** @internal */
class CurrentRun
{
    /** @var list<Run> */
    private array $stack = [];

    /** @var WeakMap<object, list<Run>> */
    private WeakMap $suspended;

    public function __construct()
    {
        $this->suspended = new WeakMap;
    }

    public function current(): ?Run
    {
        return $this->stack[array_key_last($this->stack)] ?? null;
    }

    public function during(Run $run, Closure $callback): mixed
    {
        $this->stack[] = $run;

        try {
            return $callback($run);
        } finally {
            array_pop($this->stack);
        }
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->suspended = new WeakMap;
    }

    public function processing(JobProcessing $event): void
    {
        if ($event->job instanceof SyncJob) {
            $this->suspended[$event->job] = $this->stack;
        }

        $this->stack = [];
        $id = $event->job->payload()['docket']['id'] ?? null;

        if (is_string($id)) {
            $this->stack[] = Run::findOrFail($id);
        }
    }

    public function finished(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        if (isset($this->suspended[$event->job])) {
            $this->stack = $this->suspended[$event->job];
            unset($this->suspended[$event->job]);
        } elseif (! $event->job instanceof SyncJob) {
            $this->reset();
        }
    }
}
