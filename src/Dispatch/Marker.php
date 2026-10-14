<?php

namespace Tey\Docket\Dispatch;

use Closure;

/** @internal */
class Marker
{
    public function __construct(public string $runId, public bool $finishes) {}

    public function handle(object $job, Closure $next): mixed
    {
        if (method_exists($job, 'batch') && $job->batch()?->cancelled()) {
            return null;
        }

        return $next($job);
    }
}
