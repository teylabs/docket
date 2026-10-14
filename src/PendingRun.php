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
use Tey\Docket\Protection\Fingerprint;
use Tey\Docket\Protection\Guard;
use Tey\Docket\Trees\RollUp;
use Throwable;

/** @api */
class PendingRun
{
    public string $name;

    public ?Model $subject = null;

    public ?Authenticatable $initiator;

    /** @var list<object|array<object>> */
    public array $jobs;

    private bool $isUnique = false;

    private bool $isDetached = false;

    private ?string $linkedFrom = null;

    private bool $repeatsAllowed = false;

    private ?string $key = null;

    protected ?int $units = null;

    private ?int $staleSeconds = null;

    private ?DateTimeInterface $expiry = null;

    private ?Run $retryOf = null;

    /** @var array<string, list<SerializableClosure>> */
    private array $callbacks = [];

    /**
     * @internal
     *
     * @param  list<object|array<object>>  $jobs
     */
    public function __construct(protected string $source, array $jobs)
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

    /** @internal */
    public function retrying(Run $run): static
    {
        $this->retryOf = $run;
        $this->name = $run->name;
        $this->subject = $run->subject;
        $this->initiator = $run->initiator instanceof Authenticatable ? $run->initiator : null;
        $this->units = $run->total;
        $this->staleSeconds = $run->stale_after;
        $serialized = $run->getAttribute('callbacks');
        if (is_string($serialized)) {
            /** @var array<string, list<SerializableClosure>> $callbacks */
            $callbacks = unserialize(base64_decode($serialized));
            $this->callbacks = $callbacks;
        }

        return $this;
    }

    public function detached(): static
    {
        $this->isDetached = true;

        return $this;
    }

    public function dispatchSync(): Ticket
    {
        return $this->start(true);
    }

    public function linkedTo(Run|Ticket|null $run): static
    {
        if ($run !== null) {
            $this->linkedFrom = $run->id;
        }

        return $this;
    }

    public function allowRepeat(): static
    {
        $this->repeatsAllowed = true;

        return $this;
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

        return $this->execute($sync, $callback);
    }

    protected function execute(bool $sync, ?Closure $callback): Ticket
    {
        $subjectKey = $this->subject?->getKey();
        $unique = $this->isUnique ? hash('sha256', serialize([$this->name, $this->subject?->getMorphClass(), is_int($subjectKey) ? (string) $subjectKey : $subjectKey])) : null;
        $key = $this->key === null ? null : hash('sha256', serialize([$this->name, $this->key]));

        if ($existing = $this->existing($unique, $key)) {
            return $existing->ticket();
        }

        $fingerprints = [];
        if ($callback !== null) {
            $fingerprints[app(Fingerprint::class)->inline($this->name, $callback)] = Closure::class;
        } else {
            foreach ($this->jobs as $group) {
                foreach (is_array($group) ? $group : [$group] as $job) {
                    $fingerprints[app(Fingerprint::class)->job($job)] = $job::class;
                }
            }
        }

        try {
            $run = (new Run)->getConnection()->transaction(function () use ($unique, $key, $callback, $sync, $fingerprints): Run {
                if ($key !== null) {
                    Run::query()->where('idempotency_key', $key)->where('expires_at', '<=', now())->update(['idempotency_key' => null]);
                }

                $run = new Run([
                    'name' => $this->name,
                    'status' => $callback === null ? RunStatus::Queued : RunStatus::Running,
                    'total' => $this->units ?? $this->declaredTotal(),
                    'total_explicit' => $this->units !== null,
                    'dispatch_sync' => $callback === null && $sync,
                    'detached' => $this->isDetached,
                    'stale_after' => $this->staleSeconds ?? ($this->source === 'job' ? ($this->jobs[0]->timeout ?? null) : null) ?? config('docket.stale_after'),
                    'expires_at' => $this->expiry,
                    'unique_key' => $unique,
                    'idempotency_key' => $key,
                    'callbacks' => $this->callbacks === [] ? null : base64_encode(serialize($this->callbacks)),
                    'replay' => app(Dispatch\Replay::class)->encode($callback === null ? $this->source : 'job', $callback === null ? $this->jobs : [$callback]),
                    'retry_of_run_id' => $this->retryOf?->id,
                    'checkpoint' => $this->retryOf?->checkpoint,
                ]);
                $run->subject()->associate($this->subject);

                if ($this->initiator !== null && ! $this->initiator instanceof Model) {
                    throw new InvalidArgumentException('A run initiator must be an Eloquent model.');
                }

                $run->initiator()->associate($this->initiator);
                $parent = $this->linkedFrom === null && $this->retryOf === null ? app(CurrentRun::class)->current() : null;
                $run->parent_run_id = $parent?->id;
                $run->root_run_id = $parent === null ? null : ($parent->root_run_id ?? $parent->id);
                $run->linked_from_run_id = $this->linkedFrom;
                $run->save();
                if ($this->retryOf !== null) {
                    foreach ($this->retryOf->items()->succeeded()->lazyById() as $item) {
                        app(Reporting\Reporter::class)->settle($run, $item->key, ItemStatus::Succeeded, $item->result, $item->code, $item->params ?? [], $item->count);
                    }
                }
                foreach ($fingerprints as $fingerprint => $job) {
                    $run->getConnection()->table('docket_fingerprints')->insert([
                        'run_id' => $run->id, 'fingerprint' => $fingerprint,
                    ]);
                }
                app(RollUp::class)->changed(null, $run);

                return $run;
            }, 5);
        } catch (QueryException $exception) {
            $run = $this->existing($unique, $key);

            if ($run === null) {
                throw $exception;
            }

            return $run->ticket();
        }

        try {
            app(Guard::class)->starting($run, $fingerprints, $this->repeatsAllowed);

            if ($callback !== null) {
                return app(InlineRunner::class)->execute($run, $callback);
            }

            $jobs = $this->jobs;
            $source = $this->source;
            app(Transactions::class)->dispatch($run->id, static fn () => app(Dispatcher::class)->enqueue($run, $source, $jobs, $sync));
        } catch (Throwable $exception) {
            app(CurrentRun::class)->failedChild($exception, $run);

            throw $exception;
        }

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
