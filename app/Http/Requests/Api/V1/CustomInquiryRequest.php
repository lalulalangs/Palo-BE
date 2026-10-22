<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name') && is_string($this->name)) {
            $merge['name'] = trim($this->name);
        }

        if ($this->has('phone') && is_string($this->phone)) {
            $merge['phone'] = trim($this->phone);
        }

        if ($this->has('note') && is_string($this->note)) {
            $merge['note'] = trim($this->note);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'exists:skus,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 100 karakter.',
            'phone.required' => 'Nomor WhatsApp / telepon wajib diisi.',
            'phone.max' => 'Nomor telepon maksimal 30 karakter.',
            'quantity.min' => 'Jumlah pesanan minimal 1.',
            'note.max' => 'Catatan pesanan maksimal 1000 karakter.',
        ];
    }
}
