<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Models\DepositRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deposits = DepositRequest::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $deposits]);
    }

    public function store(StoreDepositRequest $request): JsonResponse
    {
        $data = $request->validated();

        $deposit = DepositRequest::create([
            'user_id' => $request->user()->id,
            'amount' => round((float) $data['amount'], 2),
            'status' => 'pending',
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $deposit], 201);
    }
}
