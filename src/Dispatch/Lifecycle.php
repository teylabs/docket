<?php

namespace Tey\Docket\Dispatch;

use Illuminate\Support\Facades\Event;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\Events\RunFinished;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Throwable;

/** @internal */
class Lifecycle
{
    /** @param array{code: string, params: array<string, scalar|null>}|null $error */
    public function finish(string $id, RunStatus $status, ?array $error = null): void
    {
        $connection = (new Run)->getConnection();
        $connection->transaction(function () use ($connection, $id, $status, $error): void {
            $run = Run::query()->lockForUpdate()->findOrFail($id);

            if ($run->finished()) {
                return;
            }

            if (in_array($status, [RunStatus::Succeeded, RunStatus::Partial], true)
                && $connection->table('docket_work')->where('run_id', $id)->where('completed', false)->exists()) {
                return;
            }

            $run->update([
                'status' => $status,
                'error' => $error,
                'finished_at' => now(),
                'expires_at' => $run->expires_at ?? now()->addSeconds(config()->integer('docket.ttl')),
                'unique_key' => null,
            ]);

            $connection->afterCommit(fn () => $this->notify($run));
        });
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
