<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PDF was previously allowed here but the vision model only accepts image data,
            // not PDFs — dropped rather than accepting uploads that will always fail extraction.
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }
}
