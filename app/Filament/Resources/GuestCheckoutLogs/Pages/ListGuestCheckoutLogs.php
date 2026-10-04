<?php

namespace App\Filament\Resources\GuestCheckoutLogs\Pages;

use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Filament\Resources\GuestCheckoutLogs\GuestCheckoutLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar niat WhatsApp, dipisah menjadi dua tab.
 *
 * Kenapa tab, bukan satu tabel dengan filter:
 *   - Dua jenis ini punya cara kerja berbeda. Yang pertama adalah checkout
 *     sungguhan (ada cart, ada estimasi total); yang kedua adalah permintaan
 *     custom/partai yang PRD §1.1A nyatakan belum punya spesifikasi, minimum
 *     kuantitas, harga, atau produksi yang terverifikasi.
 *   - Kalau digabung, CS harus repot-repot memfilter setiap kali ingin
 *     menjawab pertanyaan yang berbeda.
 *
 * Scope query di tiap tab memakai scope yang sudah ada di model
 * (`checkoutIntents()` dan `customInquiries()`), bukan filter UI, sehingga
 * tab tidak bisa salah baca karena filter tersimpan di URL.
 */
class ListGuestCheckoutLogs extends ListRecords
{
    protected static string $resource = GuestCheckoutLogResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'checkout' => Tab::make('Checkout')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->checkoutIntents())
                ->badge(fn (): int => GuestCheckoutLog::query()->checkoutIntents()->count()),

            'custom' => Tab::make('Custom / Partai')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->customInquiries())
                ->badge(fn (): int => GuestCheckoutLog::query()->customInquiries()->count()),
        ];
    }
}
