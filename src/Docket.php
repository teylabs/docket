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
        return $this->pending()->run($name, $callback);
    }

    public function current(): ?Run
    {
        return app(CurrentRun::class)->current();
    }

    public function job(object $job): PendingRun
    {
        return $this->pending('job', [$job]);
    }

    /** @param iterable<object|array<object>> $jobs */
    public function batch(iterable $jobs): PendingRun
    {
        return $this->pending('batch', array_values(is_array($jobs) ? $jobs : iterator_to_array($jobs)));
    }

    /** @param array<object> $jobs */
    public function chain(array $jobs): PendingRun
    {
        return $this->pending('chain', array_values($jobs));
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
        return $this->pending()->about($subject);
    }

    public function by(?Authenticatable $user): PendingRun
    {
        return $this->pending()->by($user);
    }

    public function name(string $name): PendingRun
    {
        return $this->pending()->name($name);
    }

    public function linkedTo(Run|Ticket|null $run): PendingRun
    {
        return $this->pending()->linkedTo($run);
    }

    public function allowRepeat(): PendingRun
    {
        return $this->pending()->allowRepeat();
    }

    public function unique(): PendingRun
    {
        return $this->pending()->unique();
    }

    public function idempotencyKey(?string $key): PendingRun
    {
        return $this->pending()->idempotencyKey($key);
    }

    public function total(int $units): PendingRun
    {
        return $this->pending()->total($units);
    }

    public function staleAfter(int $seconds): PendingRun
    {
        return $this->pending()->staleAfter($seconds);
    }

    public function expiresAt(DateTimeInterface $time): PendingRun
    {
        return $this->pending()->expiresAt($time);
    }

    public function then(Closure $callback): PendingRun
    {
        return $this->pending()->then($callback);
    }

    public function catch(Closure $callback): PendingRun
    {
        return $this->pending()->catch($callback);
    }

    public function finally(Closure $callback): PendingRun
    {
        return $this->pending()->finally($callback);
    }

    /** @param list<object|array<object>> $jobs */
    protected function pending(string $source = 'options', array $jobs = []): PendingRun
    {
        return new PendingRun($source, $jobs);
    }
}
