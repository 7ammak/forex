<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LedgerServiceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        $dbPath = config('database.connections.'.config('database.default').'.database');
        if ($dbPath === ':memory:') {
            $this->markTestSkipped('Concurrency test requires a file-based DB so subprocesses can share state.');
        }

        // Improve write concurrency: WAL allows readers + one writer; busy_timeout makes
        // contended writers wait instead of failing immediately with SQLITE_BUSY.
        DB::statement('PRAGMA journal_mode=WAL');
        DB::statement('PRAGMA busy_timeout=10000');
    }

    public function test_concurrent_writes_preserve_balance_equals_sum_invariant(): void
    {
        $user = User::factory()->create();

        $workers = 5;
        $iterations = 20;
        $opsPerIteration = 2;
        $expectedTxCount = $workers * $iterations * $opsPerIteration;

        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, 'PHP CLI executable not found');
        $artisan = base_path('artisan');

        $env = [
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
        ];

        /** @var Process[] $processes */
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $p = new Process(
                command: [$php, $artisan, 'ledger:worker', (string) $user->id, (string) $iterations],
                cwd: base_path(),
                env: $env,
                timeout: 120,
            );
            $p->start();
            $processes[] = $p;
        }

        foreach ($processes as $i => $p) {
            $p->wait();
            $this->assertSame(
                0,
                $p->getExitCode(),
                "Worker #$i failed.\nSTDOUT:\n{$p->getOutput()}\nSTDERR:\n{$p->getErrorOutput()}"
            );
        }

        $actualCount = Transaction::where('user_id', $user->id)->count();
        $rawSum = (float) Transaction::where('user_id', $user->id)->sum('amount');
        $balance = app(LedgerService::class)->balanceFor($user);

        $this->assertSame(
            $expectedTxCount,
            $actualCount,
            "Expected $expectedTxCount transactions from $workers workers × $iterations iters × 2 ops; got $actualCount"
        );
        $this->assertEqualsWithDelta(0.0, $rawSum, 0.001, 'Equal credits and debits should sum to zero');
        $this->assertEqualsWithDelta($rawSum, $balance, 0.001, 'balanceFor must equal raw sum of amounts');
    }
}
