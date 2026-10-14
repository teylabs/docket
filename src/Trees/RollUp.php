<?php

namespace Tey\Docket\Trees;

use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal */
class RollUp
{
    /** Called inside the transaction that creates or locks and updates the child. */
    public function changed(?Run $before, Run $after): void
    {
        while ($after->parent_run_id !== null) {
            $parent = Run::query()->lockForUpdate()->findOrFail($after->parent_run_id);
            $previous = clone $parent;
            $old = $this->contribution($before);
            $new = $this->contribution($after);
            $increments = [];

            foreach ($new as $column => $value) {
                if ($value !== $old[$column]) {
                    $increments[$column] = $value - $old[$column];
                }
            }

            if ($increments === []) {
                return;
            }

            $parent->newQuery()->whereKey($parent->id)->incrementEach([
                ...$increments,
                'revision' => 1,
            ], ['updated_at' => now()]);

            // Finalize here without a second ancestor walk: one pass even when a tree finishes.
            if (! $after->detached && $after->finished()) {
                app(Lifecycle::class)->finish($parent->id, RunStatus::Succeeded, propagate: false);
            }

            $before = $previous;
            $after = $parent->refresh();
        }
    }

    /** @return array<string, int> */
    private function contribution(?Run $run): array
    {
        $attached = $run !== null && ! $run->detached;
        $progress = $run?->progress;

        return [
            'children_total' => (int) ($run !== null),
            'children_running' => (int) ($run !== null && ! $run->finished()),
            'children_succeeded' => (int) ($run?->succeeded() ?? false),
            'children_partial' => (int) ($run?->partial() ?? false),
            'children_failed' => (int) ($run?->failed() ?? false),
            'attached_children' => (int) $attached,
            'pending_children' => (int) ($attached && ! $run->finished()),
            'unsuccessful_children' => (int) ($attached && ($run->failed() || $run->partial())),
            'children_done' => $attached ? ($progress->total === null ? (int) $run->finished() : $progress->done) : 0,
            'children_units' => $attached ? ($progress->total ?? 1) : 0,
        ];
    }
}
