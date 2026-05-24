<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => [
                'nullable',
                Rule::in([
                    'admin_credit',
                    'admin_debit',
                    'trade_stake',
                    'trade_payout',
                    'deposit_approved',
                    'withdrawal_approved',
                ]),
            ],
        ]);

        $transactions = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $transactions]);
    }
}
