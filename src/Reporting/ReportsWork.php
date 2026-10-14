<?php

namespace Tey\Docket\Reporting;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tey\Docket\Dispatch\Dispatcher;
use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\ItemStatus;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal @mixin Run */
trait ReportsWork
{
    /** @internal */
    public ?string $reportingKey = null;

    public function step(string $label): static
    {
        if (strlen($label) > 255) {
            throw new InvalidArgumentException('A step may contain at most 255 bytes.');
        }

        app(Reporter::class)->change($this, fn (Run $run) => $run->step = $label);

        return $this;
    }

    public function total(int $units): static
    {
        if ($units < 0) {
            throw new InvalidArgumentException('The total must not be negative.');
        }

        app(Reporter::class)->change($this, fn (Run $run) => $run->fill(['total' => $units, 'total_explicit' => true]));

        return $this;
    }

    /**
     * @template T
     *
     * @param  iterable<array-key, T>  $items
     * @param  (Closure(T, array-key): (string|int))|null  $key
     * @return Generator<string, T>
     */
    public function track(iterable $items, ?Closure $key = null): Generator
    {
        if (is_array($items) || $items instanceof Collection) {
            $declared = [];

            foreach ($items as $index => $item) {
                $declared[] = [Values::key($key === null ? $item : $key($item, $index)), $item];
            }

            app(Reporter::class)->declare($this, array_column($declared, 0), true);

            return $this->tracked($declared);
        }

        $declared = (function () use ($items, $key) {
            foreach ($items as $index => $item) {
                $itemKey = Values::key($key === null ? $item : $key($item, $index));
                app(Reporter::class)->declare($this, [$itemKey], false);
                yield [$itemKey, $item];
            }
        })();

        return $this->tracked($declared);
    }

    /**
     * @template T
     *
     * @param  iterable<array-key, array{string, T}>  $items
     * @return Generator<string, T>
     */
    private function tracked(iterable $items): Generator
    {
        foreach ($items as [$key, $item]) {
            if (app(Reporter::class)->succeeded($this, $key)) {
                continue;
            }

            $this->throwIfCancelled();
            yield $key => $item;
            $this->itemSucceeded($key);
        }
    }

    public function itemSucceeded(?string $key = null, mixed $result = null, ?string $code = null, int $count = 1): static
    {
        if ($result !== null && ! is_string($result) && ! is_int($result)) {
            throw new InvalidArgumentException('An item result must be a string or integer reference.');
        }

        if ($count < 1) {
            throw new InvalidArgumentException('An item count must be positive.');
        }

        if ($code !== null) {
            Values::code($code);
        }

        app(Reporter::class)->settle($this, Values::key($key ?? $this->reportingKey), ItemStatus::Succeeded, $result === null ? null : (string) $result, $code, count: $count);

        return $this;
    }

    /** @param array<array-key, mixed> $params */
    public function itemFailed(?string $key = null, string $code = '', array $params = []): static
    {
        return $this->itemOutcome($key, ItemStatus::Failed, $code, $params);
    }

    /** @param array<array-key, mixed> $params */
    public function itemSkipped(?string $key = null, string $code = '', array $params = []): static
    {
        return $this->itemOutcome($key, ItemStatus::Skipped, $code, $params);
    }

    /** @param array<array-key, mixed> $params */
    private function itemOutcome(?string $key, ItemStatus $status, string $code, array $params): static
    {
        Values::code($code);
        $params = Values::params($params);
        app(Reporter::class)->settle($this, Values::key($key ?? $this->reportingKey), $status, code: $code, params: $params);

        return $this;
    }

    /** @param array<array-key, mixed> $params */
    public function log(string $code, array $params = []): static
    {
        Values::code($code);
        $params = Values::params($params);
        app(Reporter::class)->change($this, fn (Run $run) => $run->logs()->create(['code' => $code, 'params' => $params]));

        return $this;
    }

    public function result(string $reference): static
    {
        app(Reporter::class)->change($this, fn (Run $run) => $run->result = $reference);

        return $this;
    }

    public function checkpoint(mixed $position = null): mixed
    {
        if (func_num_args() === 0) {
            return $this->refresh()->checkpoint;
        }

        Values::checkpoint($position);
        app(Reporter::class)->change($this, fn (Run $run) => $run->checkpoint = $position);

        return $this;
    }

    /** @param object|iterable<object|array<object>> $jobs */
    public function add(object|iterable $jobs): static
    {
        app(Dispatcher::class)->add($this, is_iterable($jobs) ? array_values([...$jobs]) : [$jobs]);

        return $this;
    }

    /** @param array<array-key, mixed> $params */
    public function fail(string $code, array $params = []): void
    {
        Values::code($code);
        $params = Values::params($params);

        throw new RunFailed($code, $params);
    }

    public function throwIfCancelled(): void
    {
        if ($this->refresh()->cancel_requested) {
            app(Lifecycle::class)->finish($this->id, RunStatus::Cancelled);

            throw new RunCancelled;
        }
    }
}
