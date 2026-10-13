<?php

namespace Tey\Docket;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Tey\Docket\Database\Model;

/**
 * @api
 *
 * @property int $id
 * @property string $run_id
 * @property string $code
 * @property array<string, scalar|null>|null $params
 * @property Carbon $created_at
 */
class Log extends Model
{
    protected $table = 'docket_logs';

    protected $guarded = [];

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
