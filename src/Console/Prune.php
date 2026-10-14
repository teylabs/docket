<?php

namespace Tey\Docket\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Events\RunExpiring;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal */
class Prune extends Command
{
    protected $signature = 'docket:prune';

    protected $description = 'Fail stale runs and delete expired runs';

    public function handle(): int
    {
        do {
            $reaped = 0;
            foreach (Run::query()->unfinished()->where('pending_children', 0)->lazyById() as $candidate) {
                $candidate->getConnection()->transaction(function () use ($candidate, &$reaped): void {
                    $run = Run::query()->lockForUpdate()->find($candidate->id);
                    if ($run === null || $run->finished() || $run->pending_children > 0) {
                        return;
                    }
                    $reported = $run->getAttribute('last_report_at');
                    $seconds = $run->stale_after ?? config()->integer('docket.stale_after');
                    if (! $reported instanceof Carbon || $reported->copy()->addSeconds($seconds)->greaterThanOrEqualTo(now()->startOfSecond())) {
                        return;
                    }

                    app(Lifecycle::class)->finish($run->id, RunStatus::Failed, ['code' => 'run.stalled', 'params' => []]);
                    $reaped++;
                }, 5);
            }
        } while ($reaped > 0);

        foreach (Run::query()->finished()->where('expires_at', '<=', now())->lazyById() as $run) {
            // Synchronous listeners finish before deletion, outside a transaction.
            // A listener failure leaves the run available for the next invocation.
            Event::dispatch(new RunExpiring($run));
            Run::query()->whereKey($run->id)->finished()->where('expires_at', '<=', now())->delete();
        }

        return self::SUCCESS;
    }
}
