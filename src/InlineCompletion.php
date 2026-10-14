<?php

namespace Tey\Docket;

use Tey\Docket\Dispatch\Work;

/** @internal */
class InlineCompletion
{
    private bool $completed = false;

    private bool $committed = false;

    public function __construct(private string $id, private string $workId) {}

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

        app(Work::class)->complete($this->id, $this->workId);
    }
}
