<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Model Master Destinasi Pengiriman (RajaOngkir).
 *
 * Disimpan di database lokal untuk pencarian ultra-cepat (< 15 ms)
 * dan mencegah pemborosan kuota API RajaOngkir Starter.
 *
 * @property int $id ID subdistrict internal RajaOngkir
 * @property string $label Label gabungan resmi
 * @property string $province_name
 * @property string $city_name
 * @property string|null $district_name
 * @property string|null $subdistrict_name
 * @property string|null $zip_code
 */
class ShippingDestination extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'label',
        'province_name',
        'city_name',
        'district_name',
        'subdistrict_name',
        'zip_code',
    ];

    /**
     * Scope pencarian multi-kolom fleksibel dan case-insensitive lintas database (SQLite & PostgreSQL).
     *
     * @param  Builder<ShippingDestination>  $query
     * @return Builder<ShippingDestination>
     */
    public function scopeSearch(Builder $query, string $keyword): Builder
    {
        $term = trim($keyword);
        if ($term === '') {
            return $query;
        }

        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $q) use ($term, $operator): void {
            $q->where('label', $operator, "%{$term}%")
                ->orWhere('subdistrict_name', $operator, "%{$term}%")
                ->orWhere('district_name', $operator, "%{$term}%")
                ->orWhere('city_name', $operator, "%{$term}%")
                ->orWhere('zip_code', 'like', "{$term}%");
        });
    }

    /**
     * Konversi ke format array DTO yang sesuai dengan kontrak frontend.
     *
     * @return array{id: int, label: string, province_name: string|null, city_name: string|null, district_name: string|null, subdistrict_name: string|null, zip_code: string|null}
     */
    public function toDestinationArray(): array
    {
        return [
            'id' => (int) $this->id,
            'label' => (string) $this->label,
            'province_name' => $this->province_name,
            'city_name' => $this->city_name,
            'district_name' => $this->district_name,
            'subdistrict_name' => $this->subdistrict_name,
            'zip_code' => $this->zip_code,
        ];
    }
}
