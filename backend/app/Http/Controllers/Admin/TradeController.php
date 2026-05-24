<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveTradeRequest;
use App\Models\AuditLog;
use App\Models\Trade;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TradeController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'outcome' => ['nullable', Rule::in(['win', 'loss'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'currency_pair_id' => ['nullable', 'integer', 'exists:currency_pairs,id'],
        ]);

        $page = Trade::query()
            ->with(['user:id,name,email', 'currencyPair'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['currency_pair_id'] ?? null, fn ($q, $v) => $q->where('currency_pair_id', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($page);
    }

    public function resolve(ResolveTradeRequest $request, Trade $trade): JsonResponse
    {
        if ($trade->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => ['This trade has already been resolved.'],
            ]);
        }

        $data = $request->validated();
        $outcome = $data['outcome'];
        $pnl = round((float) $data['pnl'], 2);
        $stake = round((float) $trade->stake, 2);

        if ($outcome === 'loss' && $pnl > $stake) {
            throw ValidationException::withMessages([
                'pnl' => ['Loss amount cannot exceed the original stake.'],
            ]);
        }

        $payout = $outcome === 'win' ? ($stake + $pnl) : ($stake - $pnl);
        $signedPnl = $outcome === 'win' ? $pnl : -$pnl;
        $admin = $request->user();

        DB::transaction(function () use ($trade, $admin, $outcome, $signedPnl, $payout) {
            if ($payout > 0) {
                $this->ledger->credit(
                    $trade->user,
                    'trade_payout',
                    $payout,
                    "Payout for trade #{$trade->id}",
                    $trade,
                );
            }

            $trade->update([
                'status' => 'closed',
                'outcome' => $outcome,
                'pnl' => $signedPnl,
                'resolved_at' => now(),
                'resolved_by' => $admin->id,
            ]);

            AuditLog::create([
                'actor_id' => $admin->id,
                'action' => 'trade.resolved',
                'target_type' => $trade->getMorphClass(),
                'target_id' => $trade->id,
                'meta' => [
                    'outcome' => $outcome,
                    'pnl' => $signedPnl,
                    'payout' => $payout,
                ],
            ]);
        });

        return response()->json([
            'data' => $trade->fresh()->load(['user:id,name,email', 'currencyPair']),
        ]);
    }
}
