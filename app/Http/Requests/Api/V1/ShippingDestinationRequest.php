<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ShippingDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search') && is_string($this->search)) {
            $this->merge(['search' => trim($this->search)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:3', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.required' => 'Kata kunci pencarian tujuan wajib diisi.',
            'search.min' => 'Kata kunci pencarian minimal 3 karakter.',
            'search.max' => 'Kata kunci pencarian maksimal 100 karakter.',
            'limit.min' => 'Jumlah hasil minimal 1.',
            'limit.max' => 'Jumlah hasil maksimal 50.',
        ];
    }
}
