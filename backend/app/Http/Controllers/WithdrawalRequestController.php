<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWithdrawalRequest;
use App\Models\WithdrawalRequest;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WithdrawalRequestController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $withdrawals = WithdrawalRequest::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $withdrawals]);
    }

    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $amount = round((float) $data['amount'], 2);

        if ($this->ledger->availableBalance($user) < $amount) {
            throw ValidationException::withMessages([
                'amount' => 'Insufficient balance for this withdrawal request.',
            ]);
        }

        $withdrawal = WithdrawalRequest::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => 'pending',
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $withdrawal], 201);
    }
}
