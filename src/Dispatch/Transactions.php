<?php

namespace Tey\Docket\Dispatch;

use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Spatie\Multitenancy\Contracts\IsTenant;
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
        $tenant = $this->currentTenant();
        $this->transactions->addCallback(function () use ($id, $callback, $tenant): void {
            unset($this->pending[$id]);

            if ($tenant === null) {
                $callback();

                return;
            }

            // The commit may land after the dispatching tenant block has ended, so the enqueue re-enters that tenant; execute() restores the
            // tenant current at commit in its own finally.
            $tenant->execute(function () use ($callback): void {
                $callback();
            });
        });
    }

    /** The current spatie/laravel-multitenancy tenant, when that package is installed and configured; it is not a dependency. */
    private function currentTenant(): ?IsTenant
    {
        if (! interface_exists(IsTenant::class) || ! app()->bound(IsTenant::class)) {
            return null;
        }

        return app(IsTenant::class)::current();
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
