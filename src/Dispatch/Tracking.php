<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Tey\Docket\ItemStatus;
use Tey\Docket\Reporting\Reporter;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use WeakMap;

/** @internal */
class Tracking
{
    /** @var WeakMap<object, Marker> */
    private WeakMap $jobs;

    public function __construct()
    {
        $this->jobs = new WeakMap;
    }

    public function attach(object $job, Marker $marker): object
    {
        $job = clone $job;
        $this->jobs[$job] = $marker;

        if (method_exists($job, 'attachDocket')) {
            $job->attachDocket($marker, ! method_exists($job, 'through'));
        }

        if (method_exists($job, 'through')) {
            $job->through([...($job->middleware ?? []), $marker]);
        }

        return $job;
    }

    public function marker(object $job): Marker
    {
        return $this->jobs[$job];
    }

    /**
     * @param  array{data?: array{command?: mixed}}  $payload
     * @return array<string, mixed>
     */
    public function payload(array $payload): array
    {
        $job = $payload['data']['command'] ?? null;

        if (! is_object($job)) {
            return [];
        }

        $marker = $this->jobs[$job] ?? null;

        foreach ($job->middleware ?? [] as $middleware) {
            if ($middleware instanceof Marker) {
                $marker = $middleware;
            }
        }

        return $marker === null ? [] : ['docket' => [
            'id' => $marker->runId,
            'finishes' => $marker->finishes,
            'work_id' => $marker->workId,
            'item_key' => $marker->itemKey,
            'batch_id' => $job->batchId ?? null,
        ]];
    }

    public function processing(JobProcessing $event): void
    {
        $id = $event->job->payload()['docket']['id'] ?? null;

        if (is_string($id)) {
            $run = Run::findOrFail($id);

            if ($run->status === RunStatus::Queued) {
                $run->update(['status' => RunStatus::Running]);
            }
        }
    }

    public function processed(JobProcessed $event): void
    {
        $metadata = $event->job->payload()['docket'] ?? null;

        if (is_array($metadata) && is_string($metadata['id']) && is_string($metadata['work_id']) && $metadata['finishes'] && ! $event->job->isReleased() && ! $event->job->hasFailed()) {
            $run = Run::findOrFail($metadata['id']);

            if (is_string($metadata['item_key'])) {
                app(Reporter::class)->settle($run, $metadata['item_key'], ItemStatus::Succeeded);
            }

            app(Work::class)->complete($run->id, $metadata['work_id']);
        }
    }

    public function failed(JobFailed $event): void
    {
        $metadata = $event->job->payload()['docket'] ?? null;

        if (is_array($metadata) && is_string($metadata['id']) && is_string($metadata['work_id'])) {
            if (is_string($metadata['item_key'])) {
                app(Reporter::class)->settle(Run::findOrFail($metadata['id']), $metadata['item_key'], ItemStatus::Failed, code: 'run.job_failed');
                app(Work::class)->complete($metadata['id'], $metadata['work_id']);

                return;
            }

            app(Lifecycle::class)->finish($metadata['id'], RunStatus::Failed, ['code' => 'run.failed', 'params' => []]);

            if (is_string($metadata['batch_id'])) {
                Bus::findBatch($metadata['batch_id'])?->cancel();
            }
        }
    }
}
