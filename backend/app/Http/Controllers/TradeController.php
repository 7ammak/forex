<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTradeRequest;
use App\Models\Trade;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TradeController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
        ]);

        $query = Trade::query()
            ->where('user_id', $request->user()->id)
            ->with('currencyPair')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(StoreTradeRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $stake = round((float) $data['stake'], 2);

        try {
            $trade = DB::transaction(function () use ($user, $data, $stake) {
                // Lock the user row so a concurrent open-trade can't pass the same balance check.
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($this->ledger->availableBalance($user) < $stake) {
                    throw new RuntimeException('Insufficient balance to open this trade.');
                }

                $trade = Trade::create([
                    'user_id' => $user->id,
                    'currency_pair_id' => $data['currency_pair_id'],
                    'direction' => $data['direction'],
                    'stake' => $stake,
                    'status' => 'open',
                    'opened_at' => now(),
                ]);

                $this->ledger->debit(
                    $user,
                    'trade_stake',
                    $stake,
                    "Trade #{$trade->id} stake",
                    $trade,
                );

                return $trade;
            });
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['stake' => $e->getMessage()]);
        }

        return response()->json(['data' => $trade->load('currencyPair')], 201);
    }

    public function show(Request $request, Trade $trade): JsonResponse
    {
        // Don't reveal existence of other users' trades — return 404, not 403.
        if ($trade->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json(['data' => $trade->load('currencyPair')]);
    }
}
