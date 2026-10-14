<?php

namespace Tey\Docket\Protection;

use Illuminate\Support\Facades\Event;
use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Events\LoopDetected as LoopDetectedEvent;
use Tey\Docket\Exceptions\DepthExceeded;
use Tey\Docket\Exceptions\LoopDetected;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal */
class Guard
{
    /** @param array<string, string> $fingerprints */
    public function starting(Run $run, array $fingerprints, bool $allowRepeat): void
    {
        $ancestors = [];
        $parent = $run->parent()->first();
        while ($parent !== null) {
            $ancestors[] = $parent;
            $parent = $parent->parent()->first();
        }

        $max = config('docket.max_depth');
        if (is_int($max) && count($ancestors) > $max) {
            $exception = new DepthExceeded($max);
            $this->fail($run, $exception);

            throw $exception;
        }

        if ($allowRepeat || $ancestors === [] || $fingerprints === []) {
            return;
        }

        $matches = $run->getConnection()->table('docket_fingerprints')
            ->whereIn('run_id', array_map(fn (Run $ancestor) => $ancestor->id, $ancestors))
            ->whereIn('fingerprint', array_keys($fingerprints))
            ->get(['run_id', 'fingerprint'])->groupBy('run_id');

        foreach ($ancestors as $ancestor) {
            foreach ($fingerprints as $fingerprint => $job) {
                if ($matches->get($ancestor->id, collect())->contains('fingerprint', $fingerprint)) {
                    $this->loop($run, $ancestor, $fingerprint, $job, $ancestor->id);
                }
            }
        }
    }

    public function loop(Run $run, Run $repeats, string $fingerprint, string $job, string $reference): never
    {
        $exception = new LoopDetected($job, $reference);
        $run->getConnection()->transaction(function () use ($run, $repeats, $fingerprint, $exception): void {
            $this->fail($run, $exception);
            $run->getConnection()->afterCommit(fn () => Event::dispatch(new LoopDetectedEvent($run->refresh(), $repeats, $fingerprint)));
        }, 5);
        report($exception);

        throw $exception;
    }

    private function fail(Run $run, RunFailed $exception): void
    {
        app(Lifecycle::class)->finish($run->id, RunStatus::Failed, ['code' => $exception->failureCode, 'params' => $exception->params]);
    }
}
