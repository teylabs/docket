<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Support\Facades\Event;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Trees\RollUp;
use Throwable;

/** @internal */
class Lifecycle
{
    /** @param array{code: string, params: array<string, scalar|null>}|null $error */
    public function finish(string $id, RunStatus $status, ?array $error = null, bool $propagate = true): void
    {
        $connection = (new Run)->getConnection();
        $connection->transaction(function () use ($connection, $id, $status, $error, $propagate): void {
            $run = Run::query()->lockForUpdate()->findOrFail($id);

            if ($run->finished()) {
                return;
            }

            $before = clone $run;

            if ($run->completion_status !== null) {
                $status = $run->completion_status;
                $error = $run->error;
            }

            if (in_array($status, [RunStatus::Succeeded, RunStatus::Partial], true)
                && $connection->table('docket_work')->where('run_id', $id)->where('completed', false)->exists()) {
                return;
            }

            if ($run->pending_children > 0) {
                $run->update([
                    'status' => RunStatus::Running,
                    'completion_status' => in_array($status, [RunStatus::Failed, RunStatus::Cancelled], true) ? $status : null,
                    'error' => $error,
                ]);

                return;
            }

            if ($status === RunStatus::Succeeded && ($run->failed_count > 0 || $run->skipped_count > 0 || $run->unsuccessful_children > 0)) {
                $status = RunStatus::Partial;
            }

            $run->update([
                'status' => $status,
                'error' => $error,
                'finished_at' => now(),
                'cancel_requested' => false,
                'expires_at' => $run->expires_at ?? now()->addSeconds(config()->integer('docket.ttl')),
                'unique_key' => null,
            ]);

            $connection->afterCommit(fn () => $this->notify($run));

            if ($propagate) {
                app(RollUp::class)->changed($before, $run);
            }
        }, 5);
    }

    private function notify(Run $run): void
    {
        Event::dispatch(new RunFinished($run));
        $serialized = $run->getAttribute('callbacks');

        if (! is_string($serialized)) {
            return;
        }

        /** @var array<string, list<SerializableClosure>> $callbacks */
        $callbacks = unserialize(base64_decode($serialized));
        $types = [$run->succeeded() ? 'then' : ($run->failed() ? 'catch' : ''), 'finally'];

        foreach ($types as $type) {
            foreach ($callbacks[$type] ?? [] as $callback) {
                try {
                    $callback($run);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }
}
