<?php

namespace Tey\Docket\Reporting;

use Closure;
use Illuminate\Support\Facades\Event;
use Tey\Docket\Events\ItemFinished;
use Tey\Docket\ItemStatus;
use Tey\Docket\Run;
use Tey\Docket\Trees\RollUp;

/** @internal */
class Reporter
{
    public function change(Run $run, Closure $callback): void
    {
        $run->getConnection()->transaction(function () use ($run, $callback): void {
            $stored = Run::query()->lockForUpdate()->findOrFail($run->id);

            if (! $stored->finished()) {
                $before = clone $stored;
                $callback($stored);
                $stored->setAttribute('updated_at', now());
                // A heartbeat is a change even when the clock or value has not moved.
                $stored->revision++;
                $stored->save();
                app(RollUp::class)->changed($before, $stored);
            }
        }, 5);

        $run->refresh();
    }

    /** @param list<string> $keys */
    public function declare(Run $run, array $keys, bool $total): void
    {
        $this->change($run, function (Run $stored) use ($keys, $total): void {
            foreach ($keys as $key) {
                $stored->items()->firstOrCreate(['key' => $key]);
            }

            if ($total && ! $stored->getAttribute('total_explicit')) {
                $stored->total = $stored->items()->count();
            }
        });
    }

    public function succeeded(Run $run, string $key): bool
    {
        if ($run->items()->where('key', $key)->succeeded()->exists()) {
            return true;
        }

        $previous = $run->retryOf?->items()->where('key', $key)->succeeded()->first();

        if ($previous !== null) {
            $this->settle($run, $key, ItemStatus::Succeeded, $previous->result, $previous->code, $previous->params ?? [], $previous->count);

            return true;
        }

        return false;
    }

    /** @param array<string, scalar|null> $params */
    public function settle(Run $run, string $key, ItemStatus $status, ?string $result = null, ?string $code = null, array $params = [], int $count = 1): void
    {
        $this->change($run, function (Run $stored) use ($key, $status, $result, $code, $params, $count): void {
            $item = $stored->items()->firstOrCreate(['key' => $key]);
            $item->refresh();

            if ($item->status !== ItemStatus::Pending) {
                return;
            }

            $item->update(['status' => $status, 'result' => $result, 'code' => $code, 'params' => $params, 'count' => $count, 'finished_at' => now()]);
            $stored->done += $count;
            $column = $status->value.'_count';
            $stored->setAttribute($column, match ($status) {
                ItemStatus::Succeeded => $stored->succeeded_count + 1,
                ItemStatus::Failed => $stored->failed_count + 1,
                ItemStatus::Skipped => $stored->skipped_count + 1,
                ItemStatus::Pending => throw new \LogicException('An outcome must be terminal.'),
            });
            $stored->getConnection()->afterCommit(fn () => Event::dispatch(new ItemFinished($stored->refresh(), $item)));
        });
    }
}
