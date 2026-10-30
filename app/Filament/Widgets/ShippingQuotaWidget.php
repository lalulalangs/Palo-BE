<?php

namespace App\Filament\Widgets;

use App\Models\AppSetting;
use App\Services\Shipping\ShippingRateService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Kesehatan layanan ongkir di dashboard admin.
 *
 * Paket Starter RajaOngkir hanya 100 HIT/hari dan checkout online menolak
 * pesanan (fail-closed) begitu tarif tidak bisa dihitung — jadi kehabisan
 * kuota berarti kehilangan penjualan, bukan sekadar ongkir tidak tampil.
 * Widget ini membuat kondisi itu terlihat sebelum jadi insiden.
 */
class ShippingQuotaWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    /**
     * Konfigurasi yang wajib terisi; bila kosong checkout membalas 503
     * dengan pesan "belum dikonfigurasi", bukan karena kuota habis.
     *
     * @return array<int, string>
     */
    public function missingConfiguration(): array
    {
        $missing = [];

        if (blank(config('shipping.api_key'))) {
            $missing[] = 'API key RajaOngkir';
        }

        if ((int) AppSetting::getValue('shipping_origin_subdistrict_id', '0') <= 0) {
            $missing[] = 'gudang asal';
        }

        if (blank(AppSetting::getValue('shipping_enabled_couriers'))) {
            $missing[] = 'kurir aktif';
        }

        return $missing;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $usage = app(ShippingRateService::class)->quotaUsage();
        $missing = $this->missingConfiguration();

        $quotaColor = match (true) {
            $usage['percent'] >= 80 => 'danger',
            $usage['percent'] >= 60 => 'warning',
            default => 'success',
        };

        $quotaHint = match (true) {
            $usage['percent'] >= 80 => 'Kuota hampir habis — pertimbangkan upgrade paket.',
            $usage['percent'] >= 60 => 'Pemakaian tinggi; pantau sampai tutup toko.',
            default => 'Aman.',
        };

        return [
            Stat::make('Kuota Ongkir Hari Ini', "{$usage['count']} / {$usage['quota']} HIT")
                ->description($quotaHint)
                ->color($quotaColor),

            Stat::make('Sisa Cek Ongkir', (string) $usage['remaining'])
                ->description('Berlaku sampai tengah malam; reset 00:00.')
                ->color($usage['remaining'] <= 0 ? 'danger' : 'gray'),

            Stat::make('Konfigurasi Ongkir', $missing === [] ? 'Siap' : 'Belum lengkap')
                ->description($missing === []
                    ? 'Checkout online bisa menghitung ongkir.'
                    : 'Kosong: '.implode(', ', $missing))
                ->color($missing === [] ? 'success' : 'danger'),
        ];
    }
}
