<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Support\Str;
use LogicException;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal */
class Work
{
    public function reserve(Run $run): string
    {
        if ($run->finished() || $run->completion_status !== null) {
            throw new LogicException('Cannot add work to a finished or stopping run.');
        }

        $id = (string) Str::ulid();
        $run->getConnection()->table('docket_work')->insert(['id' => $id, 'run_id' => $run->id]);

        return $id;
    }

    public function complete(string $runId, string $id): void
    {
        $connection = (new Run)->getConnection();
        $connection->transaction(function () use ($connection, $runId, $id): void {
            $run = Run::query()->lockForUpdate()->findOrFail($runId);
            $connection->table('docket_work')->where('run_id', $runId)->where('id', $id)->update(['completed' => true]);
            app(Lifecycle::class)->finish($runId, $run->failed_count > 0 || $run->skipped_count > 0 ? RunStatus::Partial : RunStatus::Succeeded);
        }, 5);
    }
}
