<?php

use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\FundRequestController as AdminFundRequestController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\TradeController as AdminTradeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyPairController;
use App\Http\Controllers\FundRequestController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TransactionController;
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

    // Unified fund-request flow. Users submit a deposit or withdrawal
    // request here; the ledger is untouched until an admin approves.
    // Admin role is rejected by the controller — admins use the admin
    // endpoints below, not this one.
    Route::get('/fund-requests', [FundRequestController::class, 'index']);
    Route::post('/fund-requests', [FundRequestController::class, 'store']);

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

        // Fund-request approvals (deposits + withdrawals in one queue).
        Route::get('/fund-requests', [AdminFundRequestController::class, 'index']);
        Route::post('/fund-requests/{fundRequest}/approve', [AdminFundRequestController::class, 'approve']);
        Route::post('/fund-requests/{fundRequest}/reject', [AdminFundRequestController::class, 'reject']);
    });
});
