<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi data pribadi (UU PDP): bersihkan pesan WA checkout tamu yang lama.
Schedule::command('pii:anonymize')->daily();

// Lepaskan pesanan pending yang melewati batas tahan stok 15 menit.
Schedule::command('orders:expire-unpaid')->everyMinute();
