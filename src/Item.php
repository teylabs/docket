<?php

namespace Tey\Docket;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Tey\Docket\Database\Model;

/**
 * @api
 *
 * @property int $id
 * @property string $run_id
 * @property string $key
 * @property ItemStatus $status
 * @property string|null $code
 * @property array<string, scalar|null>|null $params
 * @property string|null $result
 * @property int $count
 * @property Carbon|null $finished_at
 */
class Item extends Model
{
    protected $table = 'docket_items';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ItemStatus::class, 'params' => 'array', 'count' => 'integer', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @param Builder<static> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ItemStatus::Pending);
    }

    /** @param Builder<static> $query */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', ItemStatus::Succeeded);
    }

    /** @param Builder<static> $query */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', ItemStatus::Failed);
    }

    /** @param Builder<static> $query */
    public function scopeSkipped(Builder $query): void
    {
        $query->where('status', ItemStatus::Skipped);
    }
}
