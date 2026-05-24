<?php

namespace Database\Seeders;

use App\Models\CurrencyPair;
use Illuminate\Database\Seeder;

class CurrencyPairSeeder extends Seeder
{
    public function run(): void
    {
        $pairs = [
            ['symbol' => 'EURUSD', 'base' => 'EUR', 'quote' => 'USD'],
            ['symbol' => 'GBPUSD', 'base' => 'GBP', 'quote' => 'USD'],
            ['symbol' => 'USDJPY', 'base' => 'USD', 'quote' => 'JPY'],
            ['symbol' => 'AUDUSD', 'base' => 'AUD', 'quote' => 'USD'],
            ['symbol' => 'USDCAD', 'base' => 'USD', 'quote' => 'CAD'],
        ];

        foreach ($pairs as $pair) {
            CurrencyPair::updateOrCreate(
                ['symbol' => $pair['symbol']],
                $pair + ['is_active' => true],
            );
        }
    }
}
