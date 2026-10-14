<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\CurrentRun;
use Tey\Docket\Events\RunDispatched;
use Tey\Docket\ItemStatus;
use Tey\Docket\Protection\Fingerprint;
use Tey\Docket\Protection\Guard;
use Tey\Docket\Protection\RepeatedWork;
use Tey\Docket\Reporting\Reporter;
use Tey\Docket\Reporting\Values;
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
            $jobs = $this->prepare($jobs, $run, $source, $sync);
            $first = $jobs[0];
            $synchronous = $sync || ($source === 'job' && ! $first instanceof ShouldQueue)
                || (is_object($first) && ($first->connection ?? config('queue.default')) === 'sync')
                || ($source === 'batch' && config('queue.default') === 'sync');

            if ($synchronous) {
                Event::dispatch(new RunDispatched($run));
            }

            if ($source === 'batch') {
                $batch = Bus::batch($jobs)->name($run->name)->allowFailures()->before(static function (Batch $batch) use ($id): void {
                    $run = Run::findOrFail($id);
                    $run->update(['batch_id' => $batch->id]);
                    if ($run->cancel_requested) {
                        $batch->cancel();
                    }
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
                if (! is_object($jobs[0])) {
                    throw new \InvalidArgumentException('A single dispatch requires a job.');
                }
                $this->dispatchJob($run, $jobs[0], $sync);
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
    private function prepare(array $jobs, Run $run, string $source, bool $sync, bool $allowRepeat = true): array
    {
        return $run->getConnection()->transaction(function () use ($jobs, $run, $source, $sync, $allowRepeat): array {
            $stored = Run::query()->lockForUpdate()->findOrFail($run->id);
            $groups = $source === 'chain' ? [$jobs] : $jobs;
            $prepared = [];
            $keys = [];

            foreach ($groups as $group) {
                $members = is_array($group) ? array_values($group) : [$group];
                foreach ($members as $member) {
                    if (! is_object($member)) {
                        throw new \InvalidArgumentException('Chains may only contain jobs.');
                    }
                }
                $first = $members[0];
                $key = method_exists($first, 'docketKey') ? Values::key($first->docketKey()) : null;
                $workId = app(Work::class)->reserve($stored);
                $chain = [];

                if ($key !== null) {
                    $keys[] = $key;
                }

                foreach ($members as $index => $member) {
                    $fingerprint = app(Fingerprint::class)->job($member);
                    $history = $run->getConnection()->table('docket_fingerprints')
                        ->where('run_id', $run->id)->where('fingerprint', $fingerprint);
                    $previous = $history->first();
                    if ($previous !== null && ! $allowRepeat) {
                        throw new RepeatedWork($fingerprint, $member::class, $previous->work_id ?? $run->id);
                    }
                    if ($previous === null) {
                        $run->getConnection()->table('docket_fingerprints')->insert([
                            'run_id' => $run->id, 'fingerprint' => $fingerprint, 'work_id' => $workId,
                        ]);
                    } elseif ($previous->work_id === null) {
                        $history->update(['work_id' => $workId]);
                    }
                    $chain[] = $this->prepareJob($member, new Marker($run->id, $index === array_key_last($members), $workId, $key), $sync);
                }

                $prepared[] = is_array($group) ? $chain : $chain[0];
            }

            if ($keys !== []) {
                app(Reporter::class)->declare($stored, $keys, true);
            }

            return $source === 'chain' && is_array($prepared[0]) ? $prepared[0] : $prepared;
        }, 5);
    }

    private function prepareJob(object $job, Marker $marker, bool $sync): object
    {
        if ($job instanceof Closure) {
            $job = new InvokeClosure($marker->runId, new SerializableClosure($job));
        }

        $job = app(Tracking::class)->attach($job, $marker);

        if ($sync && method_exists($job, 'onConnection')) {
            $job->onConnection('sync');
        }

        return $job;
    }

    /** @param list<object|array<object>> $jobs */
    public function add(Run $run, array $jobs, bool $allowRepeat = false): void
    {
        if ($jobs === []) {
            return;
        }

        $sync = (bool) $run->getAttribute('dispatch_sync');
        $jobs = array_values(array_filter($jobs, function (object|array $job) use ($run): bool {
            $first = is_array($job) ? ($job[0] ?? null) : $job;

            if (! is_object($first)) {
                throw new \InvalidArgumentException('Added chains must not be empty.');
            }

            return ! method_exists($first, 'docketKey') || ! app(Reporter::class)->succeeded($run, Values::key($first->docketKey()));
        }));
        try {
            $prepared = $this->prepare($jobs, $run, 'batch', $sync, $allowRepeat);
        } catch (RepeatedWork $repeat) {
            app(Guard::class)->loop($run, $run, $repeat->fingerprint, $repeat->job, $repeat->reference);
        }

        app(Transactions::class)->dispatch($run->id, function () use ($run, $prepared, $sync): void {
            try {
                foreach ($prepared as $job) {
                    if ($run->refresh()->finished() || $run->completion_status !== null) {
                        break;
                    }

                    if (is_array($job)) {
                        $chain = Bus::chain($job);
                        if ($sync) {
                            $chain->onConnection('sync');
                        }
                        $chain->dispatch();
                    } else {
                        $this->dispatchJob($run, $job, $sync);
                    }
                }
            } catch (Throwable $exception) {
                app(Lifecycle::class)->finish($run->id, RunStatus::Failed, ['code' => 'run.failed', 'params' => []]);

                throw $exception;
            }
        });
        $run->refresh();
    }

    private function dispatchJob(Run $run, object $job, bool $sync): void
    {
        if (! $job instanceof ShouldQueue || ($sync && ! method_exists($job, 'onConnection'))) {
            $run->refresh();
            if ($run->status === RunStatus::Queued) {
                $run->update(['status' => RunStatus::Running]);
            }
            $marker = app(Tracking::class)->marker($job);
            app(CurrentRun::class)->during($run, fn () => $marker->handle($job, static fn () => Bus::dispatchSync($job)));
            if ($marker->itemKey !== null) {
                app(Reporter::class)->settle($run, $marker->itemKey, ItemStatus::Succeeded);
            }
            app(Work::class)->complete($run->id, $marker->workId);
        } elseif ($sync) {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }
    }
}
