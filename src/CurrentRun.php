<?php

namespace Tey\Docket;

use Closure;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;
use WeakMap;

/** @internal */
class CurrentRun
{
    /** @var list<Run> */
    private array $stack = [];

    /** @var WeakMap<object, list<Run>> */
    private WeakMap $suspended;

    /** @var WeakMap<Throwable, array{parent: string, child: string}> */
    private WeakMap $failures;

    public function __construct()
    {
        $this->suspended = new WeakMap;
        $this->failures = new WeakMap;
    }

    public function current(): ?Run
    {
        $key = array_key_last($this->stack);

        return $key === null ? null : $this->stack[$key];
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
        $this->failures = new WeakMap;
    }

    public function failedChild(Throwable $exception, Run $child): void
    {
        if ($child->parent_run_id !== null && ! $child->detached) {
            $this->failures[$exception] = ['parent' => $child->parent_run_id, 'child' => $child->id];
        }
    }

    public function failureChild(Throwable $exception, Run $parent): ?string
    {
        $failure = $this->failures[$exception] ?? null;

        return $failure !== null && $failure['parent'] === $parent->id ? $failure['child'] : null;
    }

    public function processing(JobProcessing $event): void
    {
        if ($event->job instanceof SyncJob) {
            $this->suspended[$event->job] = $this->stack;
        }

        $this->stack = [];
        $id = $event->job->payload()['docket']['id'] ?? null;

        if (is_string($id)) {
            $run = Run::findOrFail($id);
            $key = $event->job->payload()['docket']['item_key'] ?? null;
            $run->reportingKey = is_string($key) ? $key : null;
            $this->stack[] = $run;
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
