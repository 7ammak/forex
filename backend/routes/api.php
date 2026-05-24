<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\TradeController as AdminTradeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyPairController;
use App\Http\Controllers\DepositRequestController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WithdrawalRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return [
        'status' => 'ok',
        'laravel' => app()->version(),
    ];
});

// Auth endpoints are rate-limited per IP to slow down credential stuffing.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Trading API (user-facing). Resolution of trades is intentionally NOT
    // exposed here — it lives under /api/admin/* in the admin module.
    Route::get('/pairs', [CurrencyPairController::class, 'index']);
    Route::get('/trades', [TradeController::class, 'index']);
    Route::get('/trades/{trade}', [TradeController::class, 'show']);
    // Opening a trade is rate-limited to prevent runaway scripts.
    Route::post('/trades', [TradeController::class, 'store'])->middleware('throttle:30,1');

    // Deposit / withdrawal requests. These only create pending rows — the
    // ledger is untouched until an admin approves the request.
    Route::apiResource('deposits', DepositRequestController::class)->only(['index', 'store']);
    Route::apiResource('withdrawals', WithdrawalRequestController::class)->only(['index', 'store']);

    // Ledger transactions (read-only). The only writer is LedgerService.
    Route::get('/transactions', [TransactionController::class, 'index']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/ping', fn () => response()->json(['message' => 'pong']));

        // Dashboard stats
        Route::get('/stats', [AdminStatsController::class, 'index']);
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);

        // User management
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::post('/users/{user}/adjust-balance', [AdminUserController::class, 'adjustBalance']);

        // Trade management
        Route::get('/trades', [AdminTradeController::class, 'index']);
        Route::post('/trades/{trade}/resolve', [AdminTradeController::class, 'resolve']);

        // Deposit / withdrawal approvals
        Route::get('/deposits', [AdminDepositController::class, 'index']);
        Route::post('/deposits/{deposit}/approve', [AdminDepositController::class, 'approve']);
        Route::post('/deposits/{deposit}/reject', [AdminDepositController::class, 'reject']);

        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject']);
    });
});
