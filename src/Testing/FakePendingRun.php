<?php

namespace Tey\Docket\Testing;

use Closure;
use Tey\Docket\PendingRun;
use Tey\Docket\RunStatus;
use Tey\Docket\Ticket;

/** @internal */
class FakePendingRun extends PendingRun
{
    /** @param list<object|array<object>> $jobs */
    public function __construct(string $source, array $jobs, private DocketFake $fake)
    {
        parent::__construct($source, $jobs);
    }

    protected function execute(bool $sync, ?Closure $callback): Ticket
    {
        $run = new FakeRun;
        $run->name = $this->name;
        $run->status = $callback === null ? RunStatus::Queued : RunStatus::Running;
        $run->setRelation('subject', $this->subject);
        $run->setRelation('initiator', $this->initiator);
        if ($this->units !== null) {
            $run->total($this->units);
        }

        return $this->fake->record($this, $this->source, $run, $callback);
    }
}
