<?php

namespace Tey\Docket\Tests\Fixtures;

use Tey\Docket\Docketable;

class PlainReportingWork extends QueuedWork
{
    use Docketable;

    public function handle(): void
    {
        $this->docket()?->fail('file.unsupported');
        parent::handle();
    }
}
