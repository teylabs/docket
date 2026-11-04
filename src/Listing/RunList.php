<?php

namespace Tey\Docket\Listing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Tey\Docket\Policies\RunPolicy;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/**
 * The runs in progress for a filter, as display-ready rows. The server decides everything a list shows: which runs appear
 * (each one passes the `view` policy), their label, current step, state, copy, failure reason and links, and when a finished
 * row drops off. A client renders the rows as given.
 *
 * Bind your own hooks in a service provider: `scopeUsing()` narrows the candidate query (a tenant or owner column), and
 * `describeUsing()` adjusts a row (its label, reason or links). Neither replaces the `view` check.
 *
 * @api
 *
 * @phpstan-type RunRow array{
 *     id: string, token: string, name: string, label: string, status: string,
 *     state: 'queued'|'running'|'slow'|'timed_out'|'succeeded'|'partial'|'failed'|'cancelled',
 *     active: bool, tone: 'neutral'|'warning'|'danger'|'success', message: string, reason: string|null,
 *     step: array{key: string, label: string}|null, steps: list<array{key: string, label: string, status: string, error?: string}>,
 *     progress: array{done: int, total: int|null, percent: int|null},
 *     started_at: string, finished_at: string|null, slow_at: string|null, timeout_at: string|null, linger_until: string|null,
 *     links: array<string, array{url: string, label: string}>
 * }
 * @phpstan-type RunFilter array{name: string, subject_type?: string|null, subject_id?: string|null, limit?: int|null, linger?: int|null}
 */
class RunList
{
    /** @var (Closure(Builder<Run>, ?Authenticatable): void)|null */
    private ?Closure $scope = null;

    /** @var (Closure(array<string, mixed>, Run): array<string, mixed>)|null */
    private ?Closure $describe = null;

    /**
     * Narrows the candidate query before the `view` check, e.g. to the current tenant. Rows still pass the policy one by one.
     *
     * @param  Closure(Builder<Run>, ?Authenticatable): void  $scope
     */
    public function scopeUsing(Closure $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    /**
     * Adjusts each row after Docket builds it: return the row with your changes (a label, a reason, a `help` link).
     *
     * @param  Closure(array<string, mixed>, Run): array<string, mixed>  $describe
     */
    public function describeUsing(Closure $describe): static
    {
        $this->describe = $describe;

        return $this;
    }

    /**
     * The rows for a filter that the viewer may view: active runs first (newest first), then runs that finished within the
     * linger window. At most `limit` rows, after reading at most `docket.runs.scan` candidates.
     *
     * @param  RunFilter  $filter
     * @return list<array<string, mixed>>
     */
    public function rows(array $filter, ?Authenticatable $viewer = null): array
    {
        $viewer ??= auth()->user();
        $limit = max(1, min(config()->integer('docket.runs.max_limit', 50), $filter['limit'] ?? config()->integer('docket.runs.limit', 10)));
        $scan = max($limit, config()->integer('docket.runs.scan', 200));
        $linger = $this->linger($filter);
        $now = now();
        $rows = [];
        $seen = 0;

        $active = $this->query($filter, $viewer)->unfinished()->orderByDesc('created_at')->orderByDesc('id');
        $finished = $this->query($filter, $viewer)->finished()
            ->where('finished_at', '>=', $now->copy()->subSeconds(max($linger, config()->integer('docket.runs.failed_linger_seconds', 600))))
            ->orderByDesc('finished_at')->orderByDesc('id');

        foreach ([$active, $finished] as $query) {
            foreach ($query->limit($scan)->cursor() as $run) {
                if (count($rows) >= $limit || ++$seen > $scan) {
                    break 2;
                }

                if ($run->expires_at?->lessThanOrEqualTo($now) || Gate::forUser($viewer)->denies('view', $run)) {
                    continue;
                }

                $row = $this->row($run, $linger, $now);

                if (is_string($row['linger_until']) && Carbon::parse($row['linger_until'])->lessThan($now)) {
                    continue;
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The whole answer the endpoint and stream send: the rows, the copy for an empty list and the next poll interval (fast while
     * any row is active, slow while idle).
     *
     * @param  RunFilter  $filter
     * @return array{schema_version: int, runs: list<array<string, mixed>>, active: bool, empty: string, poll_after_ms: int, generated_at: string}
     */
    public function payload(array $filter, ?Authenticatable $viewer = null): array
    {
        $rows = $this->rows($filter, $viewer);
        $active = array_filter($rows, fn (array $row) => $row['active'] === true) !== [];

        return [
            'schema_version' => 1,
            'runs' => $rows,
            'active' => $active,
            'empty' => config()->string('docket.runs.copy.empty', 'Nothing running.'),
            'poll_after_ms' => $active ? config()->integer('docket.poll_after_ms', 2000) : config()->integer('docket.runs.idle_poll_ms', 15000),
            'generated_at' => self::time(now()),
        ];
    }

    /**
     * One run as a row. `slow` once active past `docket.runs.slow_after`, `timed_out` once active past its timeout (`docket.runs.timeout_after`,
     * else the run's own stale interval, the same one `docket:prune --stale` uses). A timed-out run stays listed until it finishes.
     *
     * @return array<string, mixed>
     */
    public function row(Run $run, ?int $linger = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $linger ??= config()->integer('docket.runs.linger_seconds', 30);
        $started = $run->created_at;
        $slowAfter = config('docket.runs.slow_after');
        $timeoutAfter = config('docket.runs.timeout_after') ?? $run->stale_after ?? config('docket.stale_after');
        $slowAt = is_numeric($slowAfter) ? $started->copy()->addSeconds((int) $slowAfter) : null;
        $timeoutAt = is_numeric($timeoutAfter) ? $started->copy()->addSeconds((int) $timeoutAfter) : null;
        $active = ! $run->finished();

        $state = match (true) {
            ! $active => $run->status->value,
            $timeoutAt !== null && $now->greaterThanOrEqualTo($timeoutAt) => 'timed_out',
            $slowAt !== null && $now->greaterThanOrEqualTo($slowAt) => 'slow',
            default => $run->status->value,
        };

        $reason = match (true) {
            $state === 'timed_out' => $this->copy('timed_out_reason'),
            in_array($run->status, [RunStatus::Failed, RunStatus::Partial], true) => $this->reason($run),
            default => null,
        };

        $steps = $run->stepList();
        $current = null;
        foreach ($steps as $step) {
            if ($step['status'] === 'active' || $step['status'] === 'failed') {
                $current = ['key' => $step['key'], 'label' => $step['label']];
            }
        }
        if ($current === null && $active && $run->step !== null) {
            $current = ['key' => $run->step, 'label' => $run->step];
        }

        $links = [];
        if ($run->status === RunStatus::Succeeded && $run->output !== null && config()->boolean('docket.routes.enabled')) {
            $expires = $now->copy()->addSeconds(config()->integer('docket.output_link_ttl'));
            $route = $run->outputViewable() ? 'docket.output.view' : 'docket.output';
            $links['output'] = ['url' => URL::temporarySignedRoute($route, $expires, ['token' => $run->token], absolute: false), 'label' => $this->copy('output')];
        }
        $help = config('docket.runs.help_url');
        if (is_string($help) && $help !== '' && in_array($state, ['timed_out', 'failed', 'partial'], true)) {
            $links['help'] = ['url' => $help, 'label' => $this->copy('help')];
        }

        $labels = config()->array('docket.runs.labels', []);
        $finishedAt = $run->finished_at;
        $lingerFor = $run->status === RunStatus::Succeeded ? $linger : max($linger, config()->integer('docket.runs.failed_linger_seconds', 600));

        $row = [
            'id' => $run->id,
            'token' => $run->token,
            'name' => $run->name,
            'label' => is_string($labels[$run->name] ?? null) ? $labels[$run->name] : $run->name,
            'status' => $run->status->value,
            'state' => $state,
            'active' => $active,
            'tone' => match ($state) {
                'slow' => 'warning',
                'timed_out', 'failed' => 'danger',
                'partial', 'cancelled' => 'warning',
                'succeeded' => 'success',
                default => 'neutral',
            },
            'message' => $this->copy($state),
            'reason' => $reason,
            'step' => $current,
            'steps' => $steps,
            'progress' => $run->progress->toArray(),
            'started_at' => self::time($started),
            'finished_at' => $finishedAt === null ? null : self::time($finishedAt),
            'slow_at' => $slowAt === null ? null : self::time($slowAt),
            'timeout_at' => $timeoutAt === null ? null : self::time($timeoutAt),
            'linger_until' => $finishedAt === null ? null : self::time($finishedAt->copy()->addSeconds($lingerFor)),
            'links' => $links,
        ];

        return $this->describe === null ? $row : ($this->describe)($row, $run);
    }

    /** @param RunFilter $filter */
    private function linger(array $filter): int
    {
        $linger = $filter['linger'] ?? config()->integer('docket.runs.linger_seconds', 30);

        return max(0, min(config()->integer('docket.runs.max_linger_seconds', 3600), $linger));
    }

    private function reason(Run $run): string
    {
        $code = $run->error['code'] ?? null;
        $reasons = config()->array('docket.runs.reasons', []);

        if (is_string($code) && is_string($reasons[$code] ?? null)) {
            return $reasons[$code];
        }

        return $this->copy($run->status->value);
    }

    /**
     * @param  RunFilter  $filter
     * @return Builder<Run>
     */
    private function query(array $filter, ?Authenticatable $viewer): Builder
    {
        $query = Run::query()->where('name', $filter['name']);

        if (($filter['subject_type'] ?? null) !== null) {
            $query->where('subject_type', $filter['subject_type']);
        }

        if (($filter['subject_id'] ?? null) !== null) {
            $query->where('subject_id', (string) $filter['subject_id']);
        }

        // With the default initiator-only policy, only the viewer's own runs can pass, so read only those.
        if ($this->scope === null && $this->defaultPolicy()) {
            $id = $viewer?->getAuthIdentifier();
            if (! $viewer instanceof Model || ! (is_string($id) || is_int($id))) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('initiator_type', $viewer->getMorphClass())->where('initiator_id', (string) $id);
            }
        }

        if ($this->scope !== null) {
            ($this->scope)($query, $viewer);
        }

        return $query;
    }

    /** The configured copy for a key (`docket.runs.copy.*`), else the key itself. */
    private function copy(string $key): string
    {
        $copy = config()->array('docket.runs.copy', []);

        return is_string($copy[$key] ?? null) ? $copy[$key] : $key;
    }

    private function defaultPolicy(): bool
    {
        $policy = Gate::getPolicyFor(Run::class);

        return is_object($policy) && $policy::class === RunPolicy::class;
    }

    private static function time(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
