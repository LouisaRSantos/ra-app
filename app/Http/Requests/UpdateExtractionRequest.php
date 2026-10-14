<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route's permission middleware and the record scope
    }

    public function rules(): array
    {
        return [
            'payment_reference_number' => ['nullable', 'string', 'max:255'],
            'cr_number' => ['nullable', 'string', 'max:255'],
            'total_amount' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'total_cwt_amount' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'deposit_date' => ['required', 'date'],
            'invoices' => ['required', 'array', 'min:1', 'max:100'],
            'invoices.*.si_number' => ['required', 'string', 'max:64'],
            // empty = "not shown on the document", stored as null rather than a made-up 0.00
            'invoices.*.amount' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'invoices.*.cwt_amount' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            // NOTE: total_amount vs the sum of invoice lines is not enforced; the spec for that
            // relationship has not been confirmed against real documents.
        ];
    }
}
