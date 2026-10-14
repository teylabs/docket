<?php

namespace Tey\Docket\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Tey\Docket\CurrentRun;
use Tey\Docket\Docket;
use Tey\Docket\PendingRun;
use Tey\Docket\Ticket;

/** @api */
class DocketFake extends Docket
{
    /** @var array<string, list<PendingRun>> */
    private array $dispatched = [];

    /** @var list<FakeRun> */
    private array $ran = [];

    /** @param list<object|array<object>> $jobs */
    protected function pending(string $source = 'options', array $jobs = []): PendingRun
    {
        return new FakePendingRun($source, $jobs, $this);
    }

    /** @internal */
    public function record(PendingRun $pending, string $source, FakeRun $run, ?Closure $callback): Ticket
    {
        if ($callback === null) {
            $this->dispatched[$source][] = clone $pending;

            return $run->ticket();
        }

        $this->ran[] = $run;
        $result = app(CurrentRun::class)->during($run, $callback);

        return $run->ticket()->withResult($result);
    }

    public function assertDispatched(string $job, Closure|int|null $callbackOrTimes = null): void
    {
        $matches = $this->matchingDispatches($job, $callbackOrTimes instanceof Closure ? $callbackOrTimes : null);

        if (is_int($callbackOrTimes)) {
            Assert::assertCount($callbackOrTimes, $matches, "Expected [{$job}] to be dispatched {$callbackOrTimes} times.");
        } else {
            Assert::assertNotEmpty($matches, "The expected job [{$job}] was not dispatched.");
        }
    }

    public function assertNotDispatched(string $job, ?Closure $callback = null): void
    {
        Assert::assertEmpty($this->matchingDispatches($job, $callback), "The unexpected job [{$job}] was dispatched.");
    }

    public function assertBatched(Closure $callback): void
    {
        Assert::assertNotEmpty(array_filter($this->dispatched['batch'] ?? [], $callback), 'The expected batch was not dispatched.');
    }

    /** @param array<class-string|object> $jobs */
    public function assertChained(array $jobs): void
    {
        $matches = array_filter($this->dispatched['chain'] ?? [], function (PendingRun $pending) use ($jobs): bool {
            if (count($pending->jobs) !== count($jobs)) {
                return false;
            }

            foreach (array_values($jobs) as $index => $job) {
                $actual = $pending->jobs[$index];
                if (is_string($job) ? ! $actual instanceof $job : $actual != $job) {
                    return false;
                }
            }

            return true;
        });

        Assert::assertNotEmpty($matches, 'The expected chain was not dispatched.');
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertEmpty($this->dispatched, 'Unexpected Docket work was dispatched.');
    }

    public function assertRan(string $name, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(array_filter($this->ran, fn (FakeRun $run) => $run->name === $name && ($callback === null || $callback($run))), "The expected inline run [{$name}] did not run.");
    }

    public function assertNotRan(string $name): void
    {
        Assert::assertEmpty(array_filter($this->ran, fn (FakeRun $run) => $run->name === $name), "The unexpected inline run [{$name}] ran.");
    }

    /** @return list<PendingRun> */
    private function matchingDispatches(string $job, ?Closure $callback): array
    {
        return array_values(array_filter($this->dispatched['job'] ?? [], fn (PendingRun $pending) => $pending->jobs[0] instanceof $job && ($callback === null || $callback($pending))));
    }
}
