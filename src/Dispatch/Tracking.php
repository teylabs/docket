<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
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

    public function attach(object $job, string $runId, bool $finishes): object
    {
        $job = clone $job;
        $marker = new Marker($runId, $finishes);
        $this->jobs[$job] = $marker;

        if (method_exists($job, 'through')) {
            $job->through([...($job->middleware ?? []), $marker]);
        }

        return $job;
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

        if (is_array($metadata) && is_string($metadata['id']) && $metadata['finishes'] && ! $event->job->isReleased() && ! $event->job->hasFailed()) {
            $run = Run::findOrFail($metadata['id']);
            app(Lifecycle::class)->finish($run->id, $run->failed_count > 0 || $run->skipped_count > 0 ? RunStatus::Partial : RunStatus::Succeeded);
        }
    }

    public function failed(JobFailed $event): void
    {
        $metadata = $event->job->payload()['docket'] ?? null;

        if (is_array($metadata) && is_string($metadata['id'])) {
            app(Lifecycle::class)->finish($metadata['id'], RunStatus::Failed, ['code' => 'run.failed', 'params' => []]);

            if (is_string($metadata['batch_id'])) {
                Bus::findBatch($metadata['batch_id'])?->cancel();
            }
        }
    }
}
