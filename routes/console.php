<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi data pribadi (UU PDP): bersihkan pesan WA checkout tamu yang lama.
Schedule::command('pii:anonymize')->daily();

// Lepaskan pesanan pending yang melewati batas tahan stok (jendela bayar DOKU).
//
// TODO(deploy): di server produksi, jadwalar ini HANYA berjalan jika cron
// Laravel terpasang — tambahkan entri crontab berikut (interval 1 menit):
//   * * * * * cd /path/ke/app && php artisan schedule:run >> /dev/null 2>&1
// Alternatif single-worker: jalankan `php artisan schedule:work` via supervisor/systemd.
// Tanpa ini, order "menunggu_pembayaran" tidak pernah kedaluwarsa dan stok
// tertahan terus (lihat temuan audit A-01).
Schedule::command('orders:expire-unpaid')->everyMinute();

// Rekonsiliasi pembayaran DOKU yang tidak menerima notifikasi (S-04):
// membandingkan payment pending dengan Check Status API DOKU.
Schedule::command('payments:reconcile-doku')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// Otomasi pembatalan pesanan COD yang tidak dikonfirmasi CS dalam batas waktu.
Schedule::command('orders:expire-unconfirmed-cod')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
