<?php

namespace Tey\Docket;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tey\Docket\Database\Model;
use Tey\Docket\Reporting\ReportsWork;

/**
 * @api
 *
 * @property string $id
 * @property string $token
 * @property string $name
 * @property RunStatus $status
 * @property int $revision
 * @property int|null $stale_after
 * @property string|null $step
 * @property int $done
 * @property int|null $total
 * @property int $succeeded_count
 * @property int $failed_count
 * @property int $skipped_count
 * @property array{code: string, params: array<string, scalar|null>}|null $error
 * @property string|null $result
 * @property mixed $checkpoint
 * @property bool $cancel_requested
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $initiator_type
 * @property string|null $initiator_id
 * @property string|null $parent_run_id
 * @property string|null $root_run_id
 * @property string|null $retry_of_run_id
 * @property string|null $linked_from_run_id
 * @property int $children_total
 * @property int $children_running
 * @property int $children_succeeded
 * @property int $children_partial
 * @property int $children_failed
 * @property bool $detached
 * @property RunStatus|null $completion_status
 * @property int $children_done
 * @property int $children_units
 * @property int $attached_children
 * @property int $pending_children
 * @property int $unsuccessful_children
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property-read Progress $progress
 */
class Run extends Model
{
    use HasUlids;
    use ReportsWork;

    protected $table = 'docket_runs';

    protected $guarded = ['id', 'token'];

    protected $attributes = [
        'status' => 'queued',
        'revision' => 1,
        'done' => 0,
        'succeeded_count' => 0,
        'failed_count' => 0,
        'skipped_count' => 0,
        'cancel_requested' => false,
        'children_total' => 0,
        'children_running' => 0,
        'children_succeeded' => 0,
        'children_partial' => 0,
        'children_failed' => 0,
        'detached' => false,
        'children_done' => 0,
        'children_units' => 0,
        'attached_children' => 0,
        'pending_children' => 0,
        'unsuccessful_children' => 0,
    ];

    /** @internal */
    protected static function booted(): void
    {
        static::creating(function (Run $run) {
            $run->token ??= bin2hex(random_bytes(32));
            $run->setAttribute('last_report_at', now());
        });

        static::updated(function (Run $run) {
            $run->revision = $run->newQuery()->findOrFail($run->id, ['revision'])->revision;
        });
    }

    /** @return array<string, mixed> */
    protected function getDirtyForUpdate(): array
    {
        return [...parent::getDirtyForUpdate(), 'revision' => $this->getConnection()->raw('revision + 1')];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'revision' => 'integer',
            'stale_after' => 'integer',
            'step' => 'string',
            'result' => 'string',
            'total_explicit' => 'boolean',
            'dispatch_sync' => 'boolean',
            'detached' => 'boolean',
            'completion_status' => RunStatus::class,
            'children_done' => 'integer',
            'children_units' => 'integer',
            'attached_children' => 'integer',
            'pending_children' => 'integer',
            'unsuccessful_children' => 'integer',
            'done' => 'integer',
            'total' => 'integer',
            'succeeded_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'children_total' => 'integer',
            'children_running' => 'integer',
            'children_succeeded' => 'integer',
            'children_partial' => 'integer',
            'children_failed' => 'integer',
            'error' => 'array',
            'checkpoint' => 'json',
            'cancel_requested' => 'boolean',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_report_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Eloquent, $this> */
    public function subject(): MorphTo
    {
        return $this->applicationMorphTo('subject');
    }

    /** @return MorphTo<Eloquent, $this> */
    public function initiator(): MorphTo
    {
        return $this->applicationMorphTo('initiator');
    }

    /** @return MorphTo<Eloquent, $this> */
    protected function applicationMorphTo(string $name): MorphTo
    {
        $type = $this->getAttribute($name.'_type');
        $class = is_string($type) ? Eloquent::getActualClassNameForMorph($type) : null;

        if ($class !== null && ! is_a($class, Eloquent::class, true)) {
            throw new InvalidArgumentException('A run subject or initiator must be an Eloquent model.');
        }

        $model = $class === null ? $this : new $class;
        $query = $model->newQuery();

        return new Database\MorphTo($query, $this, $name.'_id', $query->getModel()->getKeyName(), $name.'_type', $name);
    }

    /** @return BelongsTo<Run, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_run_id')->withDefault(fn (Run $root, Run $run) => $run);
    }

    /** @return BelongsTo<Run, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function linkedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_from_run_id');
    }

    /** @return HasMany<Run, $this> */
    public function linked(): HasMany
    {
        return $this->hasMany(self::class, 'linked_from_run_id');
    }

    /** @return HasMany<Run, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    /** @return Collection<int, Run> */
    public function descendants(): Collection
    {
        return app(Trees\Traversal::class)->descendants($this);
    }

    /** @return array<string, mixed> */
    public function tree(?Authenticatable $viewer = null): array
    {
        return app(Trees\Traversal::class)->tree($this, $viewer ?? auth()->user());
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** @return HasMany<Log, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    /** @return Collection<string, string|null> */
    public function results(): Collection
    {
        return $this->items()->whereNotNull('result')->orderBy('finished_at')->orderBy('id')->get(['key', 'result'])
            ->mapWithKeys(fn (Item $item) => [$item->key => $item->result]);
    }

    /** @return Attribute<Progress, never> */
    protected function progress(): Attribute
    {
        return Attribute::get(fn () => new Progress(
            $this->done + $this->children_done,
            $this->total === null && $this->attached_children === 0 ? null : ($this->total ?? 0) + $this->children_units,
        ));
    }

    public function finished(): bool
    {
        return $this->status->isFinished();
    }

    public function succeeded(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }

    public function partial(): bool
    {
        return $this->status === RunStatus::Partial;
    }

    public function failed(): bool
    {
        return $this->status === RunStatus::Failed;
    }

    public function cancelled(): bool
    {
        return $this->status === RunStatus::Cancelled;
    }

    public function cancelRequested(): bool
    {
        return $this->cancel_requested;
    }

    public function cancel(): static
    {
        app(Control::class)->cancel($this);

        return $this;
    }

    public function retry(): PendingRun
    {
        return app(Dispatch\Replay::class)->pending($this);
    }

    public function ticket(): Ticket
    {
        return new Ticket($this->id, $this->token, $this->name, $this->status, $this->revision);
    }

    /** @param Builder<static> $query */
    public function scopeNamed(Builder $query, string $name): void
    {
        $query->where('name', $name);
    }

    /** @param Builder<static> $query */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [RunStatus::Queued, RunStatus::Running]);
    }

    /** @param Builder<static> $query */
    public function scopeFinished(Builder $query): void
    {
        $query->whereNotIn('status', [RunStatus::Queued, RunStatus::Running]);
    }

    /** @param Builder<static> $query */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', RunStatus::Succeeded);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $links = [];

        if (config()->boolean('docket.routes.enabled')) {
            $poll = url(trim(config()->string('docket.routes.prefix'), '/').'/runs/'.$this->token);
            $links = ['poll' => $poll, 'items' => $poll.'/items', 'logs' => $poll.'/logs'];

            if ($this->children_total > 0) {
                $links['children'] = $poll.'/children';
            }

            if (! $this->finished() && Gate::allows('cancel', $this)) {
                $links['cancel'] = $poll.'/cancel';
            }

            if (in_array($this->status, [RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled], true) && Gate::allows('retry', $this)) {
                $links['retry'] = $poll.'/retry';
            }
        }

        return [
            'schema_version' => 1,
            'id' => $this->id,
            'token' => $this->token,
            'name' => $this->name,
            'status' => $this->status->value,
            'revision' => $this->revision,
            'step' => $this->step,
            'progress' => $this->progress->toArray(),
            'counts' => [
                'processed' => $this->succeeded_count + $this->failed_count + $this->skipped_count,
                'succeeded' => $this->succeeded_count,
                'failed' => $this->failed_count,
                'skipped' => $this->skipped_count,
            ],
            'error' => $this->error === null || ! $this->failed() ? null : ['code' => $this->error['code'], 'params' => (object) $this->error['params']],
            'result' => $this->result,
            'partial' => $this->items()->whereNotNull('result')->orderByDesc('finished_at')->orderByDesc('id')->limit(20)
                ->get(['key', 'result'])->map(fn (Item $item) => ['key' => $item->key, 'result' => $item->result])->all(),
            'cancel_requested' => $this->cancel_requested,
            'parent_run_id' => $this->parent_run_id,
            'root_run_id' => $this->root_run_id,
            'retry_of_run_id' => $this->retry_of_run_id,
            'linked_from_run_id' => $this->linked_from_run_id,
            'children' => [
                'total' => $this->children_total,
                'running' => $this->children_running,
                'succeeded' => $this->children_succeeded,
                'partial' => $this->children_partial,
                'failed' => $this->children_failed,
            ],
            'created_at' => $this->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => $this->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $this->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'poll_after_ms' => $this->finished() ? null : config('docket.poll_after_ms'),
            'links' => $links,
        ];
    }
}
