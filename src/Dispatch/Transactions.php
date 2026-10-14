<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Tey\Docket\RunStatus;

/** @internal */
class Transactions
{
    /** @var array<string, array{connection: string, level: int}> */
    private array $pending = [];

    public function __construct(private DatabaseTransactionsManager $transactions) {}

    public function dispatch(string $id, Closure $callback): void
    {
        $transaction = $this->transactions->getPendingTransactions()->last();

        if ($transaction === null) {
            $callback();

            return;
        }

        $this->pending[$id] = ['connection' => $transaction->connection, 'level' => $transaction->level];
        $this->transactions->addCallback(function () use ($id, $callback): void {
            unset($this->pending[$id]);
            $callback();
        });
    }

    public function committed(TransactionCommitted $event): void
    {
        foreach ($this->pending as &$pending) {
            if ($pending['connection'] === $event->connectionName) {
                $pending['level'] = min($pending['level'], $event->connection->transactionLevel());
            }
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        foreach ($this->pending as $id => $pending) {
            if ($pending['connection'] === $event->connectionName && $pending['level'] > $event->connection->transactionLevel()) {
                unset($this->pending[$id]);
                app(Lifecycle::class)->finish($id, RunStatus::Failed, ['code' => 'run.rolled_back', 'params' => []]);
            }
        }
    }
}
