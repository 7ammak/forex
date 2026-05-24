<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = DepositRequest::query()
            ->with('user:id,name,email')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($page);
    }

    public function approve(Request $request, DepositRequest $deposit): JsonResponse
    {
        $this->ensurePending($deposit);
        $note = $request->input('note');
        $admin = $request->user();

        DB::transaction(function () use ($deposit, $admin, $note) {
            $amount = round((float) $deposit->amount, 2);

            $this->ledger->credit(
                $deposit->user,
                'deposit_approved',
                $amount,
                "Approved deposit #{$deposit->id}",
                $deposit,
            );

            $deposit->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'note' => $note ?? $deposit->note,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'deposit.approved',
                'target_type' => $deposit->getMorphClass(),
                'target_id' => $deposit->id,
                'meta' => ['amount' => $amount, 'note' => $note],
            ]);
        });

        return response()->json(['data' => $deposit->fresh()]);
    }

    public function reject(Request $request, DepositRequest $deposit): JsonResponse
    {
        $this->ensurePending($deposit);
        $note = $request->input('note');
        $admin = $request->user();

        DB::transaction(function () use ($deposit, $admin, $note) {
            $deposit->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'note' => $note ?? $deposit->note,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'deposit.rejected',
                'target_type' => $deposit->getMorphClass(),
                'target_id' => $deposit->id,
                'meta' => ['note' => $note],
            ]);
        });

        return response()->json(['data' => $deposit->fresh()]);
    }

    private function ensurePending(DepositRequest $deposit): void
    {
        if ($deposit->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["This deposit request has already been {$deposit->status}."],
            ]);
        }
    }
}
