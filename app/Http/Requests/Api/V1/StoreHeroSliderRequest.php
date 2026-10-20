<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\SafeLinkUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreHeroSliderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'series_tag' => ['nullable', 'string', 'max:255'],
            'location_tag' => ['nullable', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image_desktop' => [
                'required', 'image', 'mimes:jpeg,jpg,png,webp',
                'max:'.config('image.max_upload_kb'),
                'dimensions:max_width=2560,max_height=2560',
            ],
            'image_mobile' => [
                'nullable', 'image', 'mimes:jpeg,jpg,png,webp',
                'max:'.config('image.max_upload_kb'),
                'dimensions:max_width=2560,max_height=2560',
            ],
            'image_alt' => ['nullable', 'string', 'max:255'],
            'primary_btn_label' => ['nullable', 'string', 'max:50'],
            'primary_btn_url' => ['required', 'string', 'max:255', new SafeLinkUrl],
            'secondary_btn_label' => ['nullable', 'string', 'max:50'],
            'secondary_btn_url' => ['nullable', 'string', 'max:255', new SafeLinkUrl],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
