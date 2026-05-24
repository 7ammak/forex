<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurrencyPair extends Model
{
    /** @use HasFactory<\Database\Factories\CurrencyPairFactory> */
    use HasFactory;

    protected $fillable = [
        'symbol',
        'base',
        'quote',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
