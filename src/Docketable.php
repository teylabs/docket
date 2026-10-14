<?php

namespace Tey\Docket;

use Tey\Docket\Dispatch\Marker;
use Tey\Docket\Testing\FakeRun;

/** @api */
trait Docketable
{
    /** @internal */
    public ?string $docketRunId = null;

    /** @internal */
    public ?Marker $docketMarker = null;

    private ?FakeRun $fakeDocket = null;

    /** @return array{static, FakeRun} */
    public function withFakeDocket(): array
    {
        $this->fakeDocket = new FakeRun;
        $this->fakeDocket->name = static::class;
        if (method_exists($this, 'docketKey')) {
            $this->fakeDocket->reportingKey = $this->docketKey();
        }

        return [$this, $this->fakeDocket];
    }

    /** @internal */
    public function attachDocket(Marker $marker, bool $middleware): void
    {
        $this->fakeDocket = null;
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
        if ($this->fakeDocket !== null) {
            return $this->fakeDocket;
        }

        if ($this->docketRunId === null) {
            return null;
        }

        $current = app(CurrentRun::class)->current();

        return $current?->id === $this->docketRunId ? $current : Run::find($this->docketRunId);
    }
}
