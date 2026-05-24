<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFundRequestRequest;
use App\Models\FundRequest;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'type' => ['nullable', Rule::in(['deposit', 'withdrawal'])],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $rows = FundRequest::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(StoreFundRequestRequest $request): JsonResponse
    {
        $user = $request->user();

        // Admins manage funds via the admin endpoints — they don't submit
        // requests for themselves.
        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'type' => ['Administrators cannot submit fund requests.'],
            ]);
        }

        $data = $request->validated();
        $amount = round((float) $data['amount'], 2);

        if ($data['type'] === 'withdrawal' && $this->ledger->availableBalance($user) < $amount) {
            throw ValidationException::withMessages([
                'amount' => ['Insufficient balance for this withdrawal request.'],
            ]);
        }

        $fundRequest = FundRequest::create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'amount' => $amount,
            'status' => 'pending',
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $fundRequest], 201);
    }
}
