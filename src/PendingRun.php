<?php

namespace Tey\Docket;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Tey\Docket\Dispatch\Dispatcher;
use Tey\Docket\Dispatch\Transactions;

/** @api */
class PendingRun
{
    public string $name;

    public ?Model $subject = null;

    public ?Authenticatable $initiator;

    /** @var list<object|array<object>> */
    public array $jobs;

    private bool $isUnique = false;

    private ?string $key = null;

    private ?int $units = null;

    private ?int $staleSeconds = null;

    private ?DateTimeInterface $expiry = null;

    /** @var array<string, list<SerializableClosure>> */
    private array $callbacks = [];

    /**
     * @internal
     *
     * @param  list<object|array<object>>  $jobs
     */
    public function __construct(private string $source, array $jobs)
    {
        $this->jobs = $jobs;
        $first = $jobs[0] ?? null;
        $this->name = match (true) {
            $source === 'options', $source === 'job' && $first instanceof Closure => 'closure',
            $source === 'job' && is_object($first) => $first::class,
            default => $source,
        };
        $this->initiator = auth()->user();
    }

    public function about(Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function by(?Authenticatable $user): static
    {
        $this->initiator = $user;

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function unique(): static
    {
        $this->isUnique = true;

        return $this;
    }

    public function idempotencyKey(?string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function total(int $units): static
    {
        if ($units < 0) {
            throw new InvalidArgumentException('The total must not be negative.');
        }

        $this->units = $units;

        return $this;
    }

    public function staleAfter(int $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The stale interval must be positive.');
        }

        $this->staleSeconds = $seconds;

        return $this;
    }

    public function expiresAt(DateTimeInterface $time): static
    {
        $this->expiry = $time;

        return $this;
    }

    public function then(Closure $callback): static
    {
        $this->callbacks['then'][] = new SerializableClosure($callback);

        return $this;
    }

    public function catch(Closure $callback): static
    {
        $this->callbacks['catch'][] = new SerializableClosure($callback);

        return $this;
    }

    public function finally(Closure $callback): static
    {
        $this->callbacks['finally'][] = new SerializableClosure($callback);

        return $this;
    }

    public function dispatch(): Ticket
    {
        return $this->start(false);
    }

    public function dispatchSync(): Ticket
    {
        return $this->start(true);
    }

    public function run(string|Closure $name, ?Closure $callback = null): Ticket
    {
        if ($this->source !== 'options') {
            throw new InvalidArgumentException('Use dispatchSync() for a pending job, batch or chain.');
        }

        if ($name instanceof Closure) {
            $callback = $name;
            $name = $this->name;
        }

        if ($callback === null) {
            throw new InvalidArgumentException('An inline run requires a closure.');
        }

        $this->name = $name;

        return $this->start(true, $callback);
    }

    private function start(bool $sync, ?Closure $callback = null): Ticket
    {
        if ($callback === null && ($this->jobs === [] || in_array([], $this->jobs, true))) {
            throw new InvalidArgumentException('Dispatch requires at least one job.');
        }

        $subjectKey = $this->subject?->getKey();
        $unique = $this->isUnique ? hash('sha256', serialize([$this->name, $this->subject?->getMorphClass(), is_int($subjectKey) ? (string) $subjectKey : $subjectKey])) : null;
        $key = $this->key === null ? null : hash('sha256', serialize([$this->name, $this->key]));

        if ($existing = $this->existing($unique, $key)) {
            return $existing->ticket();
        }

        try {
            $run = (new Run)->getConnection()->transaction(function () use ($unique, $key, $callback, $sync): Run {
                if ($key !== null) {
                    Run::query()->where('idempotency_key', $key)->where('expires_at', '<=', now())->update(['idempotency_key' => null]);
                }

                $run = new Run([
                    'name' => $this->name,
                    'status' => $callback === null ? RunStatus::Queued : RunStatus::Running,
                    'total' => $this->units ?? $this->declaredTotal(),
                    'total_explicit' => $this->units !== null,
                    'dispatch_sync' => $callback === null && $sync,
                    'stale_after' => $this->staleSeconds ?? ($this->source === 'job' ? ($this->jobs[0]->timeout ?? null) : null) ?? config('docket.stale_after'),
                    'expires_at' => $this->expiry,
                    'unique_key' => $unique,
                    'idempotency_key' => $key,
                    'callbacks' => $this->callbacks === [] ? null : base64_encode(serialize($this->callbacks)),
                ]);
                $run->subject()->associate($this->subject);

                if ($this->initiator !== null && ! $this->initiator instanceof Model) {
                    throw new InvalidArgumentException('A run initiator must be an Eloquent model.');
                }

                $run->initiator()->associate($this->initiator);
                $run->save();

                return $run;
            }, 5);
        } catch (QueryException $exception) {
            $run = $this->existing($unique, $key);

            if ($run === null) {
                throw $exception;
            }

            return $run->ticket();
        }

        if ($callback !== null) {
            return app(InlineRunner::class)->execute($run, $callback);
        }

        $jobs = $this->jobs;
        $source = $this->source;
        app(Transactions::class)->dispatch($run->id, static fn () => app(Dispatcher::class)->enqueue($run, $source, $jobs, $sync));

        return $run->refresh()->ticket();
    }

    private function existing(?string $unique, ?string $key): ?Run
    {
        if ($key !== null) {
            $run = Run::query()->where('idempotency_key', $key)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();

            if ($run !== null) {
                return $run;
            }
        }

        return $unique === null ? null : Run::query()->unfinished()->named($this->name)
            ->where('subject_type', $this->subject?->getMorphClass())
            ->where('subject_id', $this->subject?->getKey())->first();
    }

    private function declaredTotal(): ?int
    {
        if ($this->source !== 'batch') {
            return null;
        }

        $keys = [];

        foreach ($this->jobs as $job) {
            $first = is_array($job) ? reset($job) : $job;

            if (is_object($first) && method_exists($first, 'docketKey')) {
                $keys[] = $first->docketKey();
            }
        }

        return $keys === [] ? null : count(array_unique($keys));
    }
}
