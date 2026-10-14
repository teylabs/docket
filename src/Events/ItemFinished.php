<?php

namespace Tey\Docket\Events;

use Illuminate\Queue\SerializesModels;
use Tey\Docket\Item;
use Tey\Docket\Run;

/** @api */
class ItemFinished
{
    use SerializesModels;

    public readonly string $id;

    public function __construct(public Run $run, public Item $item)
    {
        $this->id = $run->id.':item:'.$item->id.':finished';
    }
}
