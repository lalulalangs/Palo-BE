<?php

namespace App\Filament\Widgets;

use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Filament\Resources\GuestCheckoutLogs\GuestCheckoutLogResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * KPI niat WhatsApp hari ini.
 *
 * PRD §8 menyebut "Guest-to-WhatsApp Conversion Rate" sebagai metrik
 * keberhasilan. Angka dihitung dari `guest_checkout_logs`, BUKAN dari
 * `orders` — lihat ARSITEKTUR.md §7:
 *
 *   "Kalau keduanya dicampur, KPInya jadi tidak bermakna."
 *
 * Alasannya: tabel `orders` tidak punya `guest_checkout_logs.order_id`. Guest
 * yang menghubungi CS lewat WhatsApp belum tentu jadi order, dan tidak boleh
 * jadi order secara otomatis (PRD §3.11, §7). Menghitung dari `orders` akan
 * selalu menghasilkan nol dan terlihat seperti produk gagal.
 *
 * Widget ini sengaja menampilkan JUMLAH, bukan rasio konversi. Rasio
 * membutuhkan definisi "jadi order" yang belum diputuskan, dan angka yang
 * salah definisi lebih berbahaya daripada tidak ada angka sama sekali.
 */
class GuestIntentsToday extends BaseWidget
{
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Niat WhatsApp Hari Ini';

    public static function canView(): bool
    {
        return Gate::check('viewAny', GuestCheckoutLog::class);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $today = GuestCheckoutLog::query()
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $checkout = GuestCheckoutLog::query()
            ->whereDate('created_at', now()->toDateString())
            ->checkoutIntents()
            ->count();

        $custom = GuestCheckoutLog::query()
            ->whereDate('created_at', now()->toDateString())
            ->customInquiries()
            ->count();

        $yesterday = GuestCheckoutLog::query()
            ->whereDate('created_at', now()->subDay()->toDateString())
            ->count();

        return [
            Stat::make('Total Niat WhatsApp', (string) number_format($today, 0, ',', '.'))
                ->description($this->deltaDescription($today, $yesterday))
                ->descriptionIcon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->url(GuestCheckoutLogResource::getUrl('index')),

            Stat::make('Checkout via WhatsApp', (string) number_format($checkout, 0, ',', '.'))
                ->description('Buyer sudah menyusun cart lalu menghubungi CS.')
                ->color('info')
                ->url(GuestCheckoutLogResource::getUrl('index', ['tab' => 'checkout'])),

            Stat::make('Custom / Partai', (string) number_format($custom, 0, ',', '.'))
                ->description('PRD §1.1A: perlakukan sebagai permintaan via CS, bukan SKU custom.')
                ->color('warning')
                ->url(GuestCheckoutLogResource::getUrl('index', ['tab' => 'custom'])),
        ];
    }

    private function deltaDescription(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return 'Belum ada pembanding kemarin.';
        }

        $diff = $current - $previous;
        $percent = round(abs($diff) / $previous * 100);

        return sprintf('%s %d%% dibanding kemarin', $diff >= 0 ? 'naik' : 'turun', $percent);
    }
}
