<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Tey\Docket\CurrentRun;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\ItemStatus;
use Tey\Docket\Reporting\Reporter;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Throwable;

/** @internal */
class Marker
{
    public function __construct(public string $runId, public bool $finishes, public string $workId, public ?string $itemKey = null) {}

    public function handle(object $job, Closure $next): mixed
    {
        if (method_exists($job, 'batch') && $job->batch()?->cancelled()) {
            return null;
        }

        $run = app(CurrentRun::class)->current() ?? Run::findOrFail($this->runId);
        $run->reportingKey = $this->itemKey;

        if ($run->failed() || $run->cancelled() || $run->completion_status !== null || ($this->itemKey !== null && app(Reporter::class)->succeeded($run, $this->itemKey))) {
            return null;
        }

        try {
            $run->throwIfCancelled();

            return $next($job);
        } catch (RunFailed $exception) {
            $child = app(CurrentRun::class)->failureChild($exception, $run);
            app(Lifecycle::class)->finish($run->id, RunStatus::Failed, $child === null
                ? ['code' => $exception->failureCode, 'params' => $exception->params]
                : ['code' => 'run.child_failed', 'params' => ['child' => $child]]);

            if (method_exists($job, 'batch')) {
                $job->batch()?->cancel();
            }

            // End normally at the queue boundary: the failure is already recorded and must not retry.
            if (property_exists($job, 'chained')) {
                $job->chained = [];
            }

            return null;
        } catch (RunCancelled) {
            if (property_exists($job, 'chained')) {
                $job->chained = [];
            }

            return null;
        } catch (Throwable $exception) {
            if ($this->itemKey === null || ($job instanceof ShouldQueue && ! (($job->job ?? null) instanceof SyncJob) && ! $run->getAttribute('dispatch_sync'))) {
                throw $exception;
            }

            app(Reporter::class)->settle($run, $this->itemKey, ItemStatus::Failed, code: 'run.job_failed');
            app(Work::class)->complete($run->id, $this->workId);
            if (property_exists($job, 'chained')) {
                $job->chained = [];
            }
            report($exception);

            return null;
        }
    }
}
