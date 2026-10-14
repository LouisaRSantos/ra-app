<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the route's permission middleware
    }

    public function rules(): array
    {
        return [
            'engine' => ['required', Rule::in(['claude', 'groq'])],
            // PDF is not accepted: the vision models only take image data.
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }
}
