<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\Events\RunDispatched;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Throwable;

/** @internal */
class Dispatcher
{
    /** @param list<object|array<object>> $jobs */
    public function enqueue(Run $run, string $source, array $jobs, bool $sync): void
    {
        $id = $run->id;

        try {
            $jobs = $this->prepare($jobs, $id, $source !== 'batch', $sync);
            $first = $jobs[0];
            $synchronous = $sync || ($source === 'job' && ! $first instanceof ShouldQueue)
                || (is_object($first) && ($first->connection ?? config('queue.default')) === 'sync')
                || ($source === 'batch' && config('queue.default') === 'sync');

            if ($synchronous) {
                Event::dispatch(new RunDispatched($run));
            }

            if ($source === 'batch') {
                $batch = Bus::batch($jobs)->name($run->name)
                    ->finally(static function (Batch $batch) use ($id): void {
                        $run = Run::findOrFail($id);
                        $status = $batch->failedJobs > 0 ? RunStatus::Failed
                            : ($run->failed_count > 0 || $run->skipped_count > 0 ? RunStatus::Partial : RunStatus::Succeeded);
                        app(Lifecycle::class)->finish($id, $status, $batch->failedJobs > 0 ? ['code' => 'run.failed', 'params' => []] : null);
                    });

                if ($sync) {
                    $batch->onConnection('sync');
                }

                $batch->dispatch();
            } elseif ($source === 'chain') {
                $chain = Bus::chain($jobs);

                if ($sync) {
                    $chain->onConnection('sync');
                }

                $chain->dispatch();
            } else {
                $job = $jobs[0];

                if (! $job instanceof ShouldQueue || ($sync && ! method_exists($job, 'onConnection'))) {
                    $run->update(['status' => RunStatus::Running]);
                    Bus::dispatchSync($job);
                    app(Lifecycle::class)->finish($id, RunStatus::Succeeded);
                } elseif ($sync) {
                    Bus::dispatchSync($job);
                } else {
                    Bus::dispatch($job);
                }
            }

            if (! $synchronous) {
                Event::dispatch(new RunDispatched($run->refresh()));
            }
        } catch (Throwable $exception) {
            app(Lifecycle::class)->finish($id, RunStatus::Failed, ['code' => 'run.failed', 'params' => []]);

            throw $exception;
        }
    }

    /**
     * @param  list<object|array<object>>  $jobs
     * @return list<object|array<object>>
     */
    private function prepare(array $jobs, string $id, bool $finishes, bool $sync): array
    {
        $prepared = [];

        foreach ($jobs as $index => $job) {
            if (is_array($job)) {
                $prepared[] = array_map(fn (object $member) => $this->prepareJob($member, $id, false, $sync), array_values($job));

                continue;
            }

            $prepared[] = $this->prepareJob($job, $id, $finishes && $index === array_key_last($jobs), $sync);
        }

        return $prepared;
    }

    private function prepareJob(object $job, string $id, bool $finishes, bool $sync): object
    {
        if ($job instanceof Closure) {
            $job = new InvokeClosure($id, new SerializableClosure($job));
        }

        $job = app(Tracking::class)->attach($job, $id, $finishes);

        if ($sync && method_exists($job, 'onConnection')) {
            $job->onConnection('sync');
        }

        return $job;
    }
}
