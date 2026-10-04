<?php

use App\Console\Commands\CheckAppSettingsCommand;
use App\Console\Commands\ExpireOrdersCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Jadwal Command Terjadwal
|--------------------------------------------------------------------------
| system_map §4.4.2: "Worker rekonsiliasi memeriksa order menunggu pembayaran
| yang webhook-nya tidak tiba."
|
| PRD §3.7 AC: "Given pembayaran melewati batas waktu, When cron job pengecekan
| berjalan, Then setelah status gateway diverifikasi order menjadi 'Expired'
| dan reservasi SKU dilepas tepat sekali."
|
| JALANKAN DI SERVER (bukan `schedule:run` manual):
|     * * * * * cd /path/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/

/*
| Expire pesanan yang lewat batas bayar.
|
| 15 menit, bukan 1 menit:
|   - Barisan expiry dibuat oleh ExpireOrdersCommand dengan batas 200 per
|     eksekusi supaya tidak membebani database.
|   - Selisih maksimum 15 menit aman karena checkout sudah menolak pembayaran
|     lewat batas (payment gateway juga menolak), jadi tidak ada money risk.
|
| `withoutOverlapping()` penting: kalau eksekusi sebelumnya masih jalan
| (mis. gateway lambat), jadwal berikutnya dilewati, bukan ditumpuk.
*/
Schedule::command(ExpireOrdersCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| Peringatan konfigurasi yang belum lengkap.
|
| Harian, jam 8 pagi — cukup untuk admin melihat sebelum jam sibuk.
| Perintah ini hanya MEMBACA dan memberi peringatan, tidak mengubah data.
*/
Schedule::command(CheckAppSettingsCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();
