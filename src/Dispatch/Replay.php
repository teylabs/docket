<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Support\Facades\Crypt;
use LogicException;
use Tey\Docket\PendingRun;
use Tey\Docket\Run;
use Throwable;

/** @internal */
class Replay
{
    /** @param list<object|array<object>> $jobs */
    public function encode(string $source, array $jobs): ?string
    {
        $wrap = static fn (object $job): object => $job instanceof Closure ? new ReplayClosure($job) : $job;
        $jobs = array_map(static fn (object|array $job): object|array => is_array($job) ? array_map($wrap, $job) : $wrap($job), $jobs);

        try {
            $serialized = serialize([$source, $jobs]);
        } catch (Throwable) {
            // Inline work need not be serializable to execute in its own process.
            return null;
        }

        return Crypt::encryptString(base64_encode($serialized));
    }

    public function pending(Run $run): PendingRun
    {
        $run->refresh();
        if (! $run->finished() || $run->succeeded()) {
            throw new LogicException('Only partial, failed or cancelled runs may be retried.');
        }

        $payload = $run->getAttribute('replay');
        if (! is_string($payload)) {
            throw new LogicException('This run has no replayable work.');
        }

        /** @var array{string, list<object|array<object>>} $decoded */
        $decoded = unserialize(base64_decode(Crypt::decryptString($payload)));
        [$source, $jobs] = $decoded;
        $unwrap = static fn (object $job): object => $job instanceof ReplayClosure ? $job->getClosure() : $job;
        $jobs = array_map(static fn (object|array $job): object|array => is_array($job) ? array_map($unwrap, $job) : $unwrap($job), $jobs);

        return (new PendingRun($source, $jobs))->retrying($run);
    }
}
