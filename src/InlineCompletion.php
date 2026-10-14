<?php

namespace Tey\Docket;

use Tey\Docket\Dispatch\Lifecycle;

/** @internal */
class InlineCompletion
{
    private bool $completed = false;

    private bool $committed = false;

    public function __construct(private string $id) {}

    public function committed(): void
    {
        $this->committed = true;
        $this->finish();
    }

    public function completed(): void
    {
        $this->completed = true;
        $this->finish();
    }

    private function finish(): void
    {
        if (! $this->completed || ! $this->committed) {
            return;
        }

        $run = Run::findOrFail($this->id);
        app(Lifecycle::class)->finish($this->id, $run->failed_count > 0 || $run->skipped_count > 0 ? RunStatus::Partial : RunStatus::Succeeded);
    }
}
