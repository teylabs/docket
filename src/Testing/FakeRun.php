<?php

namespace Tey\Docket\Testing;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\ItemStatus;
use Tey\Docket\Reporting\Values;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;
use Tey\Docket\Ticket;

/** @api */
class FakeRun extends Run
{
    /** @var array<string, list<mixed>> */
    private array $reports = [];

    /** @var array<string, array{status: ItemStatus, code: ?string}> */
    private array $outcomes = [];

    /** @var array<string, true> */
    private array $declared = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->id = (string) Str::ulid();
        $this->token = bin2hex(random_bytes(32));
        $this->name ??= 'closure';
        $this->setRelation('subject', null);
        $this->setRelation('initiator', null);
    }

    public function ticket(): Ticket
    {
        return new FakeTicket($this);
    }

    public function step(string $label): static
    {
        if (strlen($label) > 255) {
            throw new InvalidArgumentException('A step may contain at most 255 bytes.');
        }

        $this->step = $label;

        return $this->record('step', $label);
    }

    public function total(int $units): static
    {
        if ($units < 0) {
            throw new InvalidArgumentException('The total must not be negative.');
        }

        $this->total = $units;

        return $this->record('total', $units);
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
        $declare = function () use ($items, $key) {
            foreach ($items as $index => $item) {
                $itemKey = Values::key($key === null ? $item : $key($item, $index));
                $this->declared[$itemKey] = true;
                yield [$itemKey, $item];
            }
        };

        if (is_array($items) || $items instanceof Collection) {
            $declared = iterator_to_array($declare());
            if (! isset($this->reports['total'])) {
                $this->total = count($this->declared);
            }
        } else {
            $declared = $declare();
        }

        return (function () use ($declared) {
            foreach ($declared as [$key, $item]) {
                if (($this->outcomes[$key]['status'] ?? null) === ItemStatus::Succeeded) {
                    continue;
                }

                $this->throwIfCancelled();
                yield $key => $item;
                $this->itemSucceeded($key);
            }
        })();
    }

    public function itemSucceeded(?string $key = null, mixed $result = null, ?string $code = null, int $count = 1): static
    {
        if ($result !== null && ! is_string($result) && ! is_int($result)) {
            throw new InvalidArgumentException('An item result must be a string or integer reference.');
        }

        if ($count < 1) {
            throw new InvalidArgumentException('An item count must be positive.');
        }

        return $this->outcome($key, ItemStatus::Succeeded, $code);
    }

    /** @param array<array-key, mixed> $params */
    public function itemFailed(?string $key = null, string $code = '', array $params = []): static
    {
        Values::params($params);

        return $this->outcome($key, ItemStatus::Failed, $code);
    }

    /** @param array<array-key, mixed> $params */
    public function itemSkipped(?string $key = null, string $code = '', array $params = []): static
    {
        Values::params($params);

        return $this->outcome($key, ItemStatus::Skipped, $code);
    }

    /** @param array<array-key, mixed> $params */
    public function log(string $code, array $params = []): static
    {
        Values::code($code);

        return $this->record('log', ['code' => $code, 'params' => Values::params($params)]);
    }

    public function result(string $reference): static
    {
        $this->result = $reference;

        return $this->record('result', $reference);
    }

    public function checkpoint(mixed $position = null): mixed
    {
        if (func_num_args() === 0) {
            return $this->checkpoint;
        }

        Values::checkpoint($position);
        $this->checkpoint = $position;

        return $this->record('checkpoint', $position);
    }

    /** @param object|iterable<object|array<object>> $jobs */
    public function add(object|iterable $jobs, bool $allowRepeat = false): static
    {
        foreach (is_iterable($jobs) ? $jobs : [$jobs] as $job) {
            foreach (is_array($job) ? $job : [$job] as $member) {
                $this->record('add', $member);
            }
        }

        return $this;
    }

    /** @param array<array-key, mixed> $params */
    public function fail(string $code, array $params = []): void
    {
        Values::code($code);
        Values::params($params);
        $this->record('fail', $code);
        parent::fail($code, $params);
    }

    public function cancel(): static
    {
        $this->cancel_requested = true;

        return $this;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancel_requested || $this->status === RunStatus::Cancelled) {
            throw new RunCancelled;
        }
    }

    public function assertStep(string $label): void
    {
        $this->assertReported('step', $label);
    }

    public function assertTotal(int $units): void
    {
        Assert::assertTrue(in_array($units, $this->reports['total'] ?? [], true) || $this->total === $units, "The expected total [{$units}] was not reported.");
    }

    public function assertItemSucceeded(string $key, ?string $code = null): void
    {
        $this->assertOutcome($key, ItemStatus::Succeeded, $code);
    }

    public function assertItemFailed(string $key, ?string $code = null): void
    {
        $this->assertOutcome($key, ItemStatus::Failed, $code);
    }

    public function assertItemSkipped(string $key, ?string $code = null): void
    {
        $this->assertOutcome($key, ItemStatus::Skipped, $code);
    }

    /** @param array<string, scalar|null>|null $params */
    public function assertLogged(string $code, ?array $params = null): void
    {
        $found = false;
        foreach ($this->reports['log'] ?? [] as $entry) {
            if (is_array($entry) && $entry['code'] === $code && ($params === null || $entry['params'] === $params)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, "The expected log [{$code}] was not reported.");
    }

    public function assertResult(string $reference): void
    {
        $this->assertReported('result', $reference);
    }

    public function assertCheckpoint(mixed $position): void
    {
        $this->assertReported('checkpoint', $position);
    }

    public function assertAdded(string|Closure $job): void
    {
        $found = false;
        foreach ($this->reports['add'] ?? [] as $added) {
            if (is_string($job) ? $added instanceof $job : $job($added)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, 'The expected job was not added.');
    }

    public function assertFailed(string $code): void
    {
        $this->assertReported('fail', $code);
    }

    private function record(string $verb, mixed $value): static
    {
        $this->reports[$verb][] = $value;

        return $this;
    }

    private function outcome(?string $key, ItemStatus $status, ?string $code): static
    {
        $key = Values::key($key ?? $this->reportingKey);
        if ($code !== null) {
            Values::code($code);
        }

        $this->declared[$key] = true;
        $this->outcomes[$key] ??= ['status' => $status, 'code' => $code];

        return $this;
    }

    private function assertOutcome(string $key, ItemStatus $status, ?string $code): void
    {
        $outcome = $this->outcomes[$key] ?? null;
        Assert::assertTrue($outcome !== null && $outcome['status'] === $status && ($code === null || $outcome['code'] === $code), "The expected {$status->value} item [{$key}] was not reported".($code === null ? '.' : " with code [{$code}]."));
    }

    private function assertReported(string $verb, mixed $value): void
    {
        Assert::assertTrue(in_array($value, $this->reports[$verb] ?? [], true), "The expected [{$verb}] was not reported.");
    }
}
