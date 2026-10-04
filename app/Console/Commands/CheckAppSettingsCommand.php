<?php

namespace App\Console\Commands;

use App\Domain\Checkout\Services\ReconciliationService;
use App\Domain\Shared\Models\AppSetting;
use Illuminate\Console\Command;

/**
 * Memeriksa konfigurasi yang belum lengkap dan memberi peringatan.
 *
 * Kenapa perlu: PRD §3.11 mensyaratkan nomor CS bisa diubah dari panel admin
 * tanpa deploy. Kalau admin belum mengisinya, guest checkout akan memakai
 * fallback dari .env — yang di produksi kemungkinan bukan nomor sebenarnya.
 * Lebih baik diperingatkan daripada diam-diam mengirim pesan ke nomor salah.
 *
 * Perintah ini HANYA membaca. Tidak pernah mengubah data.
 */
class CheckAppSettingsCommand extends Command
{
    protected $signature = 'palorinjani:check-settings';

    protected $description = 'Periksa pengaturan aplikasi yang wajib diisi sebelum toko beroperasi';

    public function handle(ReconciliationService $reconciliation): int
    {
        $missing = [];
        $placeholder = [];

        foreach (AppSetting::requiredKeys() as $key) {
            $value = AppSetting::get($key);

            if ($value === null || $value === '') {
                $missing[] = $key;

                continue;
            }

            // Nilai placeholder (teks dalam kurung siku) berarti admin belum
            // menggantinya dengan data asli. Bergabung dengan "belum diisi"
            // karena dari sisi user hasilnya sama: tidak ada data nyata.
            if (is_string($value) && str_starts_with($value, '[')) {
                $placeholder[] = $key;
            }
        }

        if ($missing !== [] || $placeholder !== []) {
            $this->warn('Konfigurasi belum lengkap:');

            foreach ($missing as $key) {
                $this->line("  - {$key}: belum diisi.");
            }

            foreach ($placeholder as $key) {
                $this->line("  - {$key}: masih placeholder.");
            }

            $this->newLine();
            $this->line('Isi lewat panel admin, atau lewat:');
            $this->line('  php artisan tinker');
            $this->line("  App\\Domain\\Shared\\Models\\AppSetting::put('store.address', 'Alamat asli');");
            $this->newLine();
            $this->line('Lihat docs/KEPUTUSAN-TERBUKA.md untuk keputusan yang masih terbuka.');

            return self::SUCCESS;
        }

        $this->info('Semua pengaturan wajib sudah terisi.');

        // Peringatan sekunder: vendor eksternal belum dipilih.
        if (config('palorinjani.gateway.name') === 'dummy') {
            $this->warn('Payment gateway masih "dummy" — checkout akan gagal. Lihat OD-01.');
        }

        if (config('palorinjani.courier.name') === 'dummy') {
            $this->warn('Kurir masih "dummy" — ongkir hanya estimasi dummy. Lihat OD-02.');
        }

        return self::SUCCESS;
    }
}
