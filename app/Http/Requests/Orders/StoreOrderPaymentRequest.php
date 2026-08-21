<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Concerns\PaymentValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderPaymentRequest extends FormRequest
{
    use PaymentValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->paymentFieldRules();
    }

    public function attributes(): array
    {
        return $this->paymentFieldAttributes();
    }
}
