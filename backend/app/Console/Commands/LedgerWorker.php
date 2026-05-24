<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Throwable;

class LedgerWorker extends Command
{
    protected $signature = 'ledger:worker {user_id} {iterations=20}';

    protected $description = 'Hammer the ledger with paired credit/debit ops (used by concurrency tests).';

    public function handle(LedgerService $ledger): int
    {
        $user = User::findOrFail($this->argument('user_id'));
        $iterations = (int) $this->argument('iterations');

        for ($i = 0; $i < $iterations; $i++) {
            $this->runWithBusyRetry(fn () => $ledger->credit($user, 'admin_credit', 1.0, "w{$i}"));
            $this->runWithBusyRetry(fn () => $ledger->debit($user, 'admin_debit', 1.0, "w{$i}"));
        }

        return self::SUCCESS;
    }

    private function runWithBusyRetry(callable $op, int $maxAttempts = 50): void
    {
        $attempt = 0;
        while (true) {
            try {
                $op();
                return;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $busy = str_contains($msg, 'database is locked')
                    || str_contains($msg, 'SQLSTATE[HY000]: General error: 5');
                if (! $busy || ++$attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep(random_int(1_000, 10_000));
            }
        }
    }
}
