<?php

namespace Tey\Docket;

use Closure;
use Tey\Docket\Dispatch\Lifecycle;
use Tey\Docket\Dispatch\Transactions;
use Tey\Docket\Dispatch\Work;
use Tey\Docket\Exceptions\RunCancelled;
use Tey\Docket\Exceptions\RunFailed;
use Throwable;

/** @internal */
class InlineRunner
{
    public function execute(Run $run, Closure $callback): Ticket
    {
        $completion = new InlineCompletion($run->id, app(Work::class)->reserve($run));
        app(Transactions::class)->dispatch($run->id, $completion->committed(...));

        try {
            $result = app(CurrentRun::class)->during($run, $callback);
        } catch (RunCancelled) {
            return $run->refresh()->ticket()->withResult(null);
        } catch (Throwable $exception) {
            $error = $exception instanceof RunFailed
                ? ['code' => $exception->failureCode, 'params' => $exception->params]
                : ['code' => 'run.exception', 'params' => []];

            if (! $exception instanceof RunFailed) {
                $run->logs()->create(['code' => 'run.exception', 'params' => ['exception' => $exception::class]]);
            }

            app(Lifecycle::class)->finish($run->id, RunStatus::Failed, $error);

            throw $exception;
        }

        app(Transactions::class)->dispatch($run->id, $completion->completed(...));

        return $run->refresh()->ticket()->withResult($result);
    }
}
