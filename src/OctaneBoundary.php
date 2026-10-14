<?php

namespace Tey\Docket;

use Illuminate\Contracts\Foundation\Application;

/** @internal */
class OctaneBoundary
{
    /** @param object{sandbox: Application} $event */
    public function handle(object $event): void
    {
        $event->sandbox->make(CurrentRun::class)->reset();
    }
}
