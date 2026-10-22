<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\SafeLinkUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHeroSliderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'series_tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_desktop' => [
                'sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp',
                'max:'.config('image.max_upload_kb'),
                'dimensions:max_width=2560,max_height=2560',
            ],
            'image_mobile' => [
                'sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp',
                'max:'.config('image.max_upload_kb'),
                'dimensions:max_width=2560,max_height=2560',
            ],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary_btn_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'primary_btn_url' => ['sometimes', 'required', 'string', 'max:255', new SafeLinkUrl],
            'secondary_btn_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'secondary_btn_url' => ['sometimes', 'nullable', 'string', 'max:255', new SafeLinkUrl],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
