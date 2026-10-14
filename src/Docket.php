<?php

namespace Tey\Docket;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** @api */
class Docket
{
    public function run(string|Closure $name, ?Closure $callback = null): Ticket
    {
        return (new PendingRun('options', []))->run($name, $callback);
    }

    public function current(): ?Run
    {
        return app(CurrentRun::class)->current();
    }

    public function job(object $job): PendingRun
    {
        return new PendingRun('job', [$job]);
    }

    /** @param iterable<object|array<object>> $jobs */
    public function batch(iterable $jobs): PendingRun
    {
        return new PendingRun('batch', array_values(is_array($jobs) ? $jobs : iterator_to_array($jobs)));
    }

    /** @param array<object> $jobs */
    public function chain(array $jobs): PendingRun
    {
        return new PendingRun('chain', array_values($jobs));
    }

    public function dispatch(object $job): Ticket
    {
        return $this->job($job)->dispatch();
    }

    public function dispatchSync(object $job): Ticket
    {
        return $this->job($job)->dispatchSync();
    }

    public function about(Model $subject): PendingRun
    {
        return (new PendingRun('options', []))->about($subject);
    }

    public function by(?Authenticatable $user): PendingRun
    {
        return (new PendingRun('options', []))->by($user);
    }

    public function name(string $name): PendingRun
    {
        return (new PendingRun('options', []))->name($name);
    }

    public function unique(): PendingRun
    {
        return (new PendingRun('options', []))->unique();
    }

    public function idempotencyKey(?string $key): PendingRun
    {
        return (new PendingRun('options', []))->idempotencyKey($key);
    }

    public function total(int $units): PendingRun
    {
        return (new PendingRun('options', []))->total($units);
    }

    public function staleAfter(int $seconds): PendingRun
    {
        return (new PendingRun('options', []))->staleAfter($seconds);
    }

    public function expiresAt(DateTimeInterface $time): PendingRun
    {
        return (new PendingRun('options', []))->expiresAt($time);
    }

    public function then(Closure $callback): PendingRun
    {
        return (new PendingRun('options', []))->then($callback);
    }

    public function catch(Closure $callback): PendingRun
    {
        return (new PendingRun('options', []))->catch($callback);
    }

    public function finally(Closure $callback): PendingRun
    {
        return (new PendingRun('options', []))->finally($callback);
    }
}
