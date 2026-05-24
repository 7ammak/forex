<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustBalanceRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $page = User::query()
            ->withSum('transactions', 'amount')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('id')
            ->paginate((int) $request->query('per_page', 15))
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'balance' => round((float) ($user->transactions_sum_amount ?? 0), 2),
                'created_at' => $user->created_at,
            ]);

        return response()->json($page);
    }

    public function update(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $admin = $request->user();
        $previousStatus = $user->status;
        $newStatus = $request->validated()['status'];

        DB::transaction(function () use ($user, $newStatus, $previousStatus, $admin) {
            $user->update(['status' => $newStatus]);

            // Suspending should also revoke all live tokens so the user can't
            // keep using a previously-issued bearer token even briefly.
            if ($newStatus === 'suspended' && $previousStatus !== 'suspended') {
                $user->tokens()->delete();
            }

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => $newStatus === 'suspended' ? 'user.suspended' : 'user.reactivated',
                'target_type' => $user->getMorphClass(),
                'target_id' => $user->id,
                'meta' => [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                ],
            ]);
        });

        return response()->json([
            'data' => $user->fresh(),
        ]);
    }

    public function adjustBalance(AdjustBalanceRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $admin = $request->user();
        $amount = round((float) $data['amount'], 2);
        $direction = $data['direction'];
        $note = $data['note'];

        $tx = DB::transaction(function () use ($user, $admin, $amount, $direction, $note) {
            $type = $direction === 'credit' ? 'admin_credit' : 'admin_debit';

            $tx = $direction === 'credit'
                ? $this->ledger->credit($user, $type, $amount, $note)
                : $this->ledger->debit($user, $type, $amount, $note);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => "user.balance.{$direction}",
                'target_type' => $user->getMorphClass(),
                'target_id' => $user->id,
                'meta' => [
                    'amount' => $amount,
                    'note' => $note,
                    'transaction_id' => $tx->id,
                ],
            ]);

            return $tx;
        });

        return response()->json([
            'transaction' => $tx,
            'balance' => $this->ledger->balanceFor($user),
        ]);
    }
}
