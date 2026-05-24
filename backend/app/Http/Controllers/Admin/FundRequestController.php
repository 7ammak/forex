<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FundRequest;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FundRequestController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in(['deposit', 'withdrawal'])],
        ]);

        $page = FundRequest::query()
            ->with('user:id,name,email')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        // Inject the requesting user's current balance into each row.
        $page->getCollection()->transform(function (FundRequest $row) {
            $row->setAttribute(
                'user_balance',
                round((float) $this->ledger->balanceFor($row->user), 2),
            );
            return $row;
        });

        return response()->json($page);
    }

    public function approve(Request $request, FundRequest $fundRequest): JsonResponse
    {
        $this->ensurePending($fundRequest);
        $admin = $request->user();

        DB::transaction(function () use ($fundRequest, $admin) {
            $user = $fundRequest->user;
            $amount = round((float) $fundRequest->amount, 2);

            // Lock the user row so a concurrent trade/credit can't slip in
            // between the balance check and the ledger write.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($fundRequest->type === 'withdrawal') {
                if ($this->ledger->availableBalance($user) < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => ["User's current balance no longer covers this withdrawal."],
                    ]);
                }
                $this->ledger->debit(
                    $user,
                    'admin_debit',
                    $amount,
                    "Approved withdrawal request #{$fundRequest->id}",
                    $fundRequest,
                );
            } else {
                $this->ledger->credit(
                    $user,
                    'admin_credit',
                    $amount,
                    "Approved deposit request #{$fundRequest->id}",
                    $fundRequest,
                );
            }

            $fundRequest->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => "fund_request.{$fundRequest->type}.approved",
                'target_type' => $fundRequest->getMorphClass(),
                'target_id' => $fundRequest->id,
                'meta' => [
                    'type' => $fundRequest->type,
                    'amount' => $amount,
                ],
            ]);
        });

        return response()->json([
            'data' => $fundRequest->fresh()->load('user:id,name,email'),
        ]);
    }

    public function reject(Request $request, FundRequest $fundRequest): JsonResponse
    {
        $this->ensurePending($fundRequest);
        $admin = $request->user();
        $note = $request->input('note');

        DB::transaction(function () use ($fundRequest, $admin, $note) {
            $fundRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'note' => $note ?? $fundRequest->note,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => "fund_request.{$fundRequest->type}.rejected",
                'target_type' => $fundRequest->getMorphClass(),
                'target_id' => $fundRequest->id,
                'meta' => [
                    'type' => $fundRequest->type,
                    'note' => $note,
                ],
            ]);
        });

        return response()->json([
            'data' => $fundRequest->fresh()->load('user:id,name,email'),
        ]);
    }

    private function ensurePending(FundRequest $fundRequest): void
    {
        if ($fundRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["This request has already been {$fundRequest->status}."],
            ]);
        }
    }
}
