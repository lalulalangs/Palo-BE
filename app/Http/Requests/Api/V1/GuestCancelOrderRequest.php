<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class GuestCancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('order_number') && is_string($this->order_number)) {
            $merge['order_number'] = trim($this->order_number);
        }

        if ($this->has('reference_code') && is_string($this->reference_code)) {
            $merge['reference_code'] = trim($this->reference_code);
        }

        if ($this->has('reason') && is_string($this->reason)) {
            $merge['reason'] = trim($this->reason);
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
            'order_number' => ['required', 'string', 'max:50'],
            'reference_code' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order_number.required' => 'Nomor pesanan wajib diisi.',
            'reference_code.required' => 'Kode referensi pesanan wajib diisi.',
            'reason.max' => 'Alasan pembatalan maksimal 255 karakter.',
        ];
    }
}
