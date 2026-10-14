<?php

namespace Tey\Docket;

use Illuminate\Support\Facades\Bus;

/** @internal */
class Control
{
    public function cancel(Run $run): void
    {
        $run->getConnection()->transaction(function () use ($run): void {
            $stored = Run::query()->lockForUpdate()->findOrFail($run->id);
            if ($stored->finished()) {
                return;
            }

            if (! $stored->cancel_requested) {
                $stored->update(['cancel_requested' => true]);
            }

            $batch = $stored->getAttribute('batch_id');
            if (is_string($batch)) {
                $stored->getConnection()->afterCommit(static fn () => Bus::findBatch($batch)?->cancel());
            }
        }, 5);
        $run->refresh();
    }
}
