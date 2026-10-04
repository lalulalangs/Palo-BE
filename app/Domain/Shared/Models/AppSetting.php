<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi yang dikelola admin.
 *
 * Kenapa tabel ini ada (PRD §3.11): "Nomor WhatsApp CS dikonfigurasi dari
 * Admin panel, bukan hardcode." AC: "Given Admin mengganti nomor WhatsApp CS,
 * When guest melakukan checkout, Then link mengarah ke nomor baru tanpa perlu
 * redeploy aplikasi."
 *
 * MAKANYA: nomor WhatsApp WAJIB disimpan di sini, bukan di .env, dan tentu
 * bukan literal di kode. Sama berlaku untuk alamat toko, jam buka, dan
 * data brand.
 *
 * Nilai disimpan sebagai JSON supaya bisa berupa string, angka, boolean, atau
 * objek (mis. koordinat peta).
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = ['key', 'value', 'group', 'label', 'description'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * Ambil nilai setting, atau nilai default bila belum diatur.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();

        return $row ? ($row->value['value'] ?? $default) : $default;
    }

    /**
     * Simpan nilai setting (upsert).
     */
    public static function put(string $key, mixed $value, string $group = 'general', ?string $label = null): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value], 'group' => $group, 'label' => $label ?? $key],
        );
    }

    /**
     * Kunci-kunci yang WAJIB ada di environment produksi.
     *
     * Dipakai oleh command `palorinjani:check-settings` untuk alerted owner
     * bahwa konfigurasi belum lengkap.
     *
     * @return array<int, string>
     */
    public static function requiredKeys(): array
    {
        return [
            'store.name',
            'store.address',
            'store.city',
            'store.phone',
            'whatsapp.cs_number',
            'whatsapp.cs_message_template',
            'shipping.origin_city',
            'shipping.origin_city_code',
        ];
    }
}
