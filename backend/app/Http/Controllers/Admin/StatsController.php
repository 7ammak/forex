<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FundRequest;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'total_users' => User::count(),
            'total_balance' => round((float) Transaction::sum('amount'), 2),
            'open_trades' => Trade::where('status', 'open')->count(),
            'pending_deposits' => FundRequest::where('status', 'pending')->where('type', 'deposit')->count(),
            'pending_withdrawals' => FundRequest::where('status', 'pending')->where('type', 'withdrawal')->count(),
        ]);
    }
}
