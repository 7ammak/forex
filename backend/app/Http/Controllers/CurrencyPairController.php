<?php

namespace App\Http\Controllers;

use App\Models\CurrencyPair;
use Illuminate\Http\JsonResponse;

class CurrencyPairController extends Controller
{
    public function index(): JsonResponse
    {
        $pairs = CurrencyPair::query()
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get();

        return response()->json(['data' => $pairs]);
    }
}
