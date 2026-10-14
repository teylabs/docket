<?php

namespace Tey\Docket\Tests\Fixtures;

use Tey\Docket\Docketable;
use Tey\Docket\Exceptions\RunFailed;
use Tey\Docket\Facades\Docket;

class RecursiveWork extends RecordWork
{
    use Docketable;

    public function __construct(public string $mode = 'child', public bool $caught = false, public mixed $input = null) {}

    public function handle(): void
    {
        $run = $this->docket() ?? throw new \LogicException('Expected a tracked run.');
        $run->log('work.started');

        try {
            if ($this->mode === 'add') {
                $run->add(new self($this->mode, $this->caught, $this->input));
            } elseif ($this->mode === 'child') {
                Docket::job(new self($this->mode, $this->caught, $this->input))->dispatch();
            } elseif ($this->mode === 'allow' && $run->parent_run_id === null) {
                Docket::job(new self($this->mode, $this->caught, $this->input))->allowRepeat()->dispatch();
            }
        } catch (RunFailed $exception) {
            if (! $this->caught) {
                throw $exception;
            }
        }
    }
}
