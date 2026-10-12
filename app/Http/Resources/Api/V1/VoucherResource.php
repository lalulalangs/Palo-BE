<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Voucher
 */
class VoucherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $desc = $this->type === Voucher::TYPE_PERCENTAGE
            ? "Potongan {$this->formattedValue()}"
            : "Potongan {$this->formattedValue()}";

        if ($this->min_purchase > 0) {
            $desc .= " dengan minimal belanja {$this->formattedMinPurchase()}";
        } else {
            $desc .= ' tanpa minimal belanja';
        }

        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => (float) $this->value,
            'formatted_value' => $this->formattedValue(),
            'min_purchase' => (float) $this->min_purchase,
            'formatted_min_purchase' => $this->formattedMinPurchase(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $desc,
        ];
    }
}
