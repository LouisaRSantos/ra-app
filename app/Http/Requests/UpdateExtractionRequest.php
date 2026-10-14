<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_reference_number' => ['nullable', 'string', 'max:255'],
            'cr_number' => ['nullable', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric'],
            'total_cwt_amount' => ['nullable', 'numeric'],
            'deposit_date' => ['required', 'date'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*.si_number' => ['required', 'string', 'max:64'],
            'invoices.*.amount' => ['required', 'numeric'],
            'invoices.*.cwt_amount' => ['required', 'numeric'],
            // NOTE: total_amount ≈ sum(invoices.*.amount - invoices.*.cwt_amount) is the expected
            // relationship per the spec, but it is not enforced here until confirmed against a
            // real sample remittance advice.
        ];
    }
}
