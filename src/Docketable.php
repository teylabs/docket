<?php

namespace Tey\Docket;

use Tey\Docket\Dispatch\Marker;

/** @api */
trait Docketable
{
    /** @internal */
    public ?string $docketRunId = null;

    /** @internal */
    public ?Marker $docketMarker = null;

    /** @internal */
    public function attachDocket(Marker $marker, bool $middleware): void
    {
        $this->docketRunId = $marker->runId;
        $this->docketMarker = $middleware ? $marker : null;
    }

    /**
     * @internal
     *
     * @return list<Marker>
     */
    public function middleware(): array
    {
        return $this->docketMarker === null ? [] : [$this->docketMarker];
    }

    public function docket(): ?Run
    {
        if ($this->docketRunId === null) {
            return null;
        }

        $current = app(CurrentRun::class)->current();

        return $current?->id === $this->docketRunId ? $current : Run::find($this->docketRunId);
    }
}
