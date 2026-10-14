<?php

namespace Tey\Docket\Tests\Fixtures;

use Tey\Docket\Docketable;
use Tey\Docket\Facades\Docket;

class ReportingWork extends KeyedWork
{
    use Docketable;

    public function handle(): void
    {
        $run = $this->docket();

        if ($run === null || $run !== Docket::current()) {
            throw new \LogicException('The job and current run must be identical.');
        }

        $run->log('job.started', ['key' => $this->label]);

        if ($this->behaviour === 'skip') {
            $run->itemSkipped(code: 'unit.locked');
        } elseif ($this->behaviour === 'stop') {
            $run->fail('file.unsupported', ['id' => 42]);
            throw new \LogicException('fail must stop the work.');
        } elseif ($this->behaviour === 'add') {
            $run->add(new self('added'));
        }

        parent::handle();
    }
}
