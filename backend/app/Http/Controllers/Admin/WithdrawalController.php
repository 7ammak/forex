<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = WithdrawalRequest::query()
            ->with('user:id,name,email')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($page);
    }

    public function approve(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        $this->ensurePending($withdrawal);
        $note = $request->input('note');
        $admin = $request->user();

        DB::transaction(function () use ($withdrawal, $admin, $note) {
            $user = $withdrawal->user;
            $amount = round((float) $withdrawal->amount, 2);

            // Lock the user row and recheck balance — the user may have
            // spent or had funds adjusted since the request was filed.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->ledger->availableBalance($user) < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ["User's current balance no longer covers this withdrawal."],
                ]);
            }

            $this->ledger->debit(
                $user,
                'withdrawal_approved',
                $amount,
                "Approved withdrawal #{$withdrawal->id}",
                $withdrawal,
            );

            $withdrawal->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'note' => $note ?? $withdrawal->note,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'withdrawal.approved',
                'target_type' => $withdrawal->getMorphClass(),
                'target_id' => $withdrawal->id,
                'meta' => ['amount' => $amount, 'note' => $note],
            ]);
        });

        return response()->json(['data' => $withdrawal->fresh()]);
    }

    public function reject(Request $request, WithdrawalRequest $withdrawal): JsonResponse
    {
        $this->ensurePending($withdrawal);
        $note = $request->input('note');
        $admin = $request->user();

        DB::transaction(function () use ($withdrawal, $admin, $note) {
            $withdrawal->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'note' => $note ?? $withdrawal->note,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'withdrawal.rejected',
                'target_type' => $withdrawal->getMorphClass(),
                'target_id' => $withdrawal->id,
                'meta' => ['note' => $note],
            ]);
        });

        return response()->json(['data' => $withdrawal->fresh()]);
    }

    private function ensurePending(WithdrawalRequest $withdrawal): void
    {
        if ($withdrawal->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["This withdrawal request has already been {$withdrawal->status}."],
            ]);
        }
    }
}
