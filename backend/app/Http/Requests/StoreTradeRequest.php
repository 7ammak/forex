<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency_pair_id' => [
                'required',
                'integer',
                Rule::exists('currency_pairs', 'id')->where('is_active', true),
            ],
            'direction' => ['required', 'string', Rule::in(['buy', 'sell'])],
            'stake' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_pair_id.exists' => 'The selected currency pair is not available for trading.',
            'direction.in' => 'Direction must be either "buy" or "sell".',
            'stake.min' => 'Stake must be greater than zero.',
        ];
    }
}
