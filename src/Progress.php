<?php

namespace Tey\Docket;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/**
 * @api
 *
 * @implements Arrayable<string, int|null>
 */
readonly class Progress implements Arrayable, JsonSerializable, Stringable
{
    public ?int $percent;

    public function __construct(public int $done, public ?int $total)
    {
        $this->percent = $total === null ? null : ($total === 0 ? 100 : max(0, min(100, (int) floor($done * 100 / $total))));
    }

    /** @return array{done: int, total: int|null, percent: int|null} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total, 'percent' => $this->percent];
    }

    /** @return array{done: int, total: int|null, percent: int|null} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        $done = number_format($this->done);

        return $this->total === null ? "$done done" : $done.' of '.number_format($this->total)." ({$this->percent}%)";
    }
}
