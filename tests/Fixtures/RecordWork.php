<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordWork implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 75;

    public int $tries = 2;

    public int $backoff = 0;

    public function __construct(public string $label = 'work', public string $behaviour = 'succeed') {}

    public function handle(): void
    {
        DB::table('work_results')->insert(['label' => $this->label]);

        if ($this->behaviour === 'fail' || ($this->behaviour === 'retry' && $this->attempts() === 1)) {
            throw new RuntimeException('private customer content');
        }

        if ($this->behaviour === 'release' && $this->attempts() === 1) {
            $this->release();
        }
    }
}
