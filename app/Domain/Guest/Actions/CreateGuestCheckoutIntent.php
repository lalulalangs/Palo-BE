<?php

namespace App\Domain\Guest\Actions;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Domain\Shared\Models\AppSetting;
use Illuminate\Support\Str;

/**
 * Membangun niat menghubungi CS via WhatsApp.
 *
 * ===================================================================
 *  ATURAN YANG SERING DILANGGAR DI SINI — BACA DULU
 * ===================================================================
 * PRD §3.11:
 *   "Saat guest menekan tombol checkout, sistem TIDAK memproses pembayaran
 *    online — melainkan generate pesan pre-filled (daftar produk, varian,
 *    quantity, estimasi total) dan redirect ke wa.me link CS."
 *
 *   "Sebelum redirect ke WhatsApp, backend memvalidasi ulang SKU/harga, membuat
 *    `guest_checkout_log` dengan kode referensi dan snapshot estimasi, lalu
 *    mengembalikan pesan serta tautan `wa.me` dari nomor CS di app settings.
 *    LOG ADALAH NIAT MENGHUBUNGKAN CS, BUKAN BUKTI PESAN TERKIRIM ATAU ORDER
 *    JADI; TIDAK MENGUNCI STOK."
 *
 * Maka fungsi ini TIDAK PERNAH:
 *   - membuat baris di tabel `orders`
 *   - membuat `stock_reservations`
 *   - memanggil payment gateway
 *   - mengunci atau mengurangi stok
 *
 * Yang dilakukan HANYA: validasi ulang harga dari server, snapshot, generate
 * pesan, catat niat, kembalikan link.
 */
class CreateGuestCheckoutIntent
{
    /**
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @return array{log: GuestCheckoutLog, reference_code: string, wa_url: string, message: string}
     */
    public function fromCart(
        array $items,
        ?string $guestName = null,
        ?string $guestPhone = null,
        ?string $note = null,
    ): array {
        return $this->persist(
            items: $items,
            guestName: $guestName,
            guestPhone: $guestPhone,
            note: $note,
            isCustomInquiry: false,
        );
    }

    /**
     * Permintaan pembelian partai / custom (PRD §6A).
     *
     * Guest ini BELUM PUNYA cart. Yang happens: tetap generates pesan WA
     * dengan konteks kebutuhan, tapi TIDAK membuat order, TIDAK mengunci stok,
     * dan TIDAK mengarang harga custom.
     *
     * PRD §7: "Kalkulator harga dan checkout otomatis untuk produk custom/
     * pembelian partai" secara eksplisit di luar scope MVP.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>|null  $items
     */
    public function forCustomInquiry(
        ?string $guestName,
        ?string $guestPhone,
        ?int $quantity,
        ?string $note,
        ?array $items = null,
    ): array {
        return $this->persist(
            items: $items ?? [],
            guestName: $guestName,
            guestPhone: $guestPhone,
            note: $note,
            isCustomInquiry: true,
            inquiryQuantity: $quantity,
        );
    }

    /**
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     */
    private function persist(
        array $items,
        ?string $guestName,
        ?string $guestPhone,
        ?string $note,
        bool $isCustomInquiry,
        ?int $inquiryQuantity = null,
    ): array {
        $snapshot = [];
        $estimatedTotal = 0;

        // ---- Re-validasi dari SERVER (PRD §3.11) ----
        // Harga & nama TIDAK PERNAH diambil dari request client.
        if ($items !== []) {
            $skus = Sku::whereIn('id', array_column($items, 'sku_id'))
                ->where('is_active', true)
                ->with(['product', 'variant'])
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $sku = $skus[$item['sku_id']] ?? null;

                if ($sku === null) {
                    // SKU hilang/nonaktif. Guest checkout tidak boleh gagal
                    // dengan error teknis; lebih baik lewati dan biarkan CS
                    // yang konfirmasi ke buyer.
                    continue;
                }

                $quantity = max(1, (int) $item['quantity']);
                $unitPrice = (int) $sku->price;
                $subtotal = $unitPrice * $quantity;
                $estimatedTotal += $subtotal;

                $snapshot[] = [
                    'sku_id' => $sku->getKey(),
                    'sku_code' => $sku->code,
                    'name' => $sku->product?->name ?? '[Nama Produk]',
                    'variant' => $sku->variant?->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];
            }
        }

        // ---- Nomor CS dari app_settings, BUKAN hardcode (PRD §3.11) ----
        $csNumber = $this->resolveCsNumber();

        // ---- Susun pesan pre-filled ----
        $message = $this->buildMessage(
            snapshot: $snapshot,
            guestName: $guestName,
            note: $note,
            isCustomInquiry: $isCustomInquiry,
            inquiryQuantity: $inquiryQuantity,
            estimatedTotal: $estimatedTotal,
        );

        $referenceCode = $this->generateReferenceCode();
        $waUrl = $this->buildWaUrl($csNumber, $message);

        // ---- Catat niat ----
        $log = GuestCheckoutLog::create([
            'reference_code' => $referenceCode,
            'cs_number_used' => $csNumber,
            'wa_url' => $waUrl,
            'item_snapshot' => $snapshot,
            'estimated_total' => $estimatedTotal,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'guest_note' => $note,
            'is_custom_inquiry' => $isCustomInquiry,
        ]);

        return [
            'log' => $log,
            'reference_code' => $referenceCode,
            'wa_url' => $waUrl,
            'message' => $message,
        ];
    }

    /**
     * Ambil nomor CS dari pengaturan admin.
     *
     * Kalau belum diatur, pakai fallback dari .env AGAR alur tidak total mati
     * di development. Di produksi, command palorinjani:check-settings membantu owner mengisinya.
     */
    private function resolveCsNumber(): string
    {
        $number = AppSetting::get('whatsapp.cs_number')
            ?? config('palorinjani.whatsapp.cs_number_fallback');

        // Normalisasi: wa.me butuh format 62xxx tanpa "+", spasi, atau tanda hubung.
        $digits = preg_replace('/\D/', '', (string) $number);

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return $digits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshot
     */
    private function buildMessage(
        array $snapshot,
        ?string $guestName,
        ?string $note,
        bool $isCustomInquiry,
        ?int $inquiryQuantity,
        int $estimatedTotal,
    ): string {
        $lines = [];

        $lines[] = 'Halo '.config('palorinjani.brand.public_name').',';
        $lines[] = '';
        $lines[] = 'Saya ingin melakukan pemesanan:';
        $lines[] = '';

        if ($isCustomInquiry) {
            $lines[] = 'Jenis: Pertanyaan pembelian partai / custom';
        }

        if ($guestName) {
            $lines[] = 'Nama: '.$guestName;
        }

        if ($isCustomInquiry && $inquiryQuantity !== null) {
            $lines[] = 'Jumlah: '.$inquiryQuantity.' item';
        }

        $lines[] = '';

        if ($snapshot !== []) {
            $lines[] = 'Produk:';
            foreach ($snapshot as $item) {
                $variant = $item['variant'] ? " ({$item['variant']})" : '';
                $lines[] = sprintf(
                    '- %s%s x%s — %s',
                    $item['name'],
                    $variant,
                    $item['quantity'],
                    $this->formatRupiah($item['subtotal']),
                );
            }
            $lines[] = '';
            $lines[] = 'Estimasi total: '.$this->formatRupiah($estimatedTotal);
        }

        if ($note) {
            $lines[] = '';
            $lines[] = 'Catatan: '.$note;
        }

        $lines[] = '';
        // PENTING: kalimat ini wajib ada. Memperjelas bahwa wa.me BUKAN
        // bukti order, sesuai PRD §3.11.
        $lines[] = '(Harga dan stok final dikonfirmasi Customer Service.)';

        return implode("\n", $lines);
    }

    private function buildWaUrl(string $csNumber, string $message): string
    {
        return 'https://wa.me/'.$csNumber.'?text='.rawurlencode($message);
    }

    private function generateReferenceCode(): string
    {
        do {
            $code = 'GC-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));
        } while (GuestCheckoutLog::where('reference_code', $code)->exists());

        return $code;
    }

    /**
     * Format rupiah; 0 ditampilkan sebagai placeholder jujur karena harga
     * belum diisi admin (PRD §1.1A).
     */
    private function formatRupiah(int $amount): string
    {
        if ($amount === 0) {
            return '[Harga dari Admin]';
        }

        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
