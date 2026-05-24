<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerService
{
    public function credit(User $user, string $type, float $amount, ?string $note, $reference = null): Transaction
    {
        return $this->record($user, $type, $amount, $note, $reference, debit: false);
    }

    public function debit(User $user, string $type, float $amount, ?string $note, $reference = null): Transaction
    {
        return $this->record($user, $type, $amount, $note, $reference, debit: true);
    }

    public function balanceFor(User $user): float
    {
        return (float) Transaction::query()
            ->where('user_id', $user->getKey())
            ->sum('amount');
    }

    public function availableBalance(User $user): float
    {
        return $this->balanceFor($user);
    }

    private function record(
        User $user,
        string $type,
        float $amount,
        ?string $note,
        ?Model $reference,
        bool $debit,
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Amount must be positive. Use credit() or debit() to choose direction.'
            );
        }

        return DB::transaction(function () use ($user, $type, $amount, $note, $reference, $debit) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return Transaction::create([
                'user_id' => $user->getKey(),
                'type' => $type,
                'amount' => $debit ? -$amount : $amount,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
            ]);
        });
    }
}
