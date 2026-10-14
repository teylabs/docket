<?php

namespace Tey\Docket\Testing;

use Tey\Docket\Run;
use Tey\Docket\Ticket;

/** @internal */
class FakeTicket extends Ticket
{
    public function __construct(private FakeRun $fakeRun)
    {
        parent::__construct($fakeRun->id, $fakeRun->token, $fakeRun->name, $fakeRun->status, $fakeRun->revision);
    }

    public function run(): Run
    {
        if (isset($this->fakeRun)) {
            return $this->fakeRun;
        }

        // PHP serialization intentionally carries only ticket fields, not reports or results.
        $run = new FakeRun;
        $run->forceFill(['id' => $this->id, 'token' => $this->token, 'name' => $this->name, 'status' => $this->status, 'revision' => $this->revision]);

        return $run;
    }
}
