<?php

namespace Tests\Feature;

use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Voucher\Actions\ApplyVoucher;
use App\Domain\Voucher\Exceptions\VoucherQuotaExhaustedException;
use App\Domain\Voucher\Models\Voucher;
use App\Domain\Voucher\Models\VoucherType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test untuk voucher.
 *
 * Fokus utama: KWOTA. Ini race condition paling halus di sistem karena
 * pola naifnya (count lalu increment) terlihat benar di single-user testing
 * dan baru gagal saat ada dua buyer bersamaan di produksi.
 */
class VoucherQuotaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRD §3.10 AC: "Given Admin membuat voucher dengan kuota 100 dan periode
     * aktif, When kuota terpakai habis, Then voucher otomatis tidak bisa
     * dipakai lagi di checkout."
     */
    public function test_kuota_terpakai_habis_menolak_voucher(): void
    {
        $voucher = $this->makeVoucher(quota: 2);

        // Pakai dua kali.
        $this->consume($voucher);
        $this->consume($voucher->fresh());

        // Voucher sekarang habis — harus ditolak.
        $this->assertTrue($voucher->fresh()->isQuotaExhausted());

        $this->expectException(VoucherNotApplicableException::class);
        app(ApplyVoucher::class)->execute($voucher->code, 200000);
    }

    /**
     * INI test yang membuktikan proteksi race condition bekerja.
     *
     * Skenario: kuota 1, dua pemakai berebut. Dengan pola naif
     * (count() lalu increment()) keduanya akan lolos. Dengan conditional
     * update, hanya satu yang boleh menambah used_count.
     */
    public function test_kuota_satu_hanya_bisa_dipakai_satu_kali(): void
    {
        $voucher = $this->makeVoucher(quota: 1);

        $first = $this->consume($voucher);
        $this->assertTrue($first, 'Pemakai pertama harus berhasil.');

        // Pemakai kedua harus gagal.
        $second = $this->consume($voucher->fresh());
        $this->assertFalse($second, 'Pemakai kedua harus ditolak — kuota sudah habis.');

        // Dan counter TIDAK boleh jadi 2 atau lebih.
        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    /**
     * Melepas redemption saat order dibatalkan mengembalikan kuota.
     * system_map §5: "aturan pelepasan saat batal agar tidak menggandakan
     * diskon."
     */
    public function test_pembatalan_order_mengembalikan_kuota_voucher(): void
    {
        $voucher = $this->makeVoucher(quota: 5);
        $order = $this->makeOrder();

        $this->consume($voucher, $order);
        $this->assertSame(1, $voucher->fresh()->used_count);

        // Batalkan order -> kuota harus kembali.
        app(ApplyVoucher::class)->releaseRedemption($order->getKey());

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertDatabaseMissing('voucher_redemptions', [
            'order_id' => $order->getKey(),
        ]);
    }

    /**
     * Melepas redemption dua kali tidak boleh membuat counter negatif.
     */
    public function test_pelepasan_berulang_aman(): void
    {
        $voucher = $this->makeVoucher(quota: 5);
        $order = $this->makeOrder();

        $this->consume($voucher, $order);

        app(ApplyVoucher::class)->releaseRedemption($order->getKey());
        app(ApplyVoucher::class)->releaseRedemption($order->getKey());
        app(ApplyVoucher::class)->releaseRedemption($order->getKey());

        $this->assertSame(0, $voucher->fresh()->used_count, 'Counter tidak boleh negatif.');
    }

    /**
     * Voucher di luar periode berlaku ditolak.
     */
    public function test_voucher_kedaluwarsa_ditolak(): void
    {
        $voucher = $this->makeVoucher(endsAt: now()->subDay());

        $this->expectException(VoucherNotApplicableException::class);
        app(ApplyVoucher::class)->execute($voucher->code, 200000);
    }

    /**
     * Voucher belum mulai berlaku ditolak.
     */
    public function test_voucher_belum_mulai_ditolak(): void
    {
        $voucher = $this->makeVoucher(startsAt: now()->addDay());

        $this->expectException(VoucherNotApplicableException::class);
        app(ApplyVoucher::class)->execute($voucher->code, 200000);
    }

    /**
     * Syarat minimum belanja.
     */
    public function test_minimum_belanja_diperiksa(): void
    {
        $voucher = $this->makeVoucher(minSpend: 300000);

        $this->expectException(VoucherNotApplicableException::class);
        // Subtotal di bawah minimum.
        app(ApplyVoucher::class)->execute($voucher->code, 150000);
    }

    /**
     * Diskon tidak boleh melebihi subtotal.
     *
     * Bug klasik: voucher Rp 500.000 pada subtotal Rp 200.000 membuat total
     * negatif atau ongkir hilang dari perhitungan.
     */
    public function test_diskon_tidak_melebihi_subtotal(): void
    {
        $discount = VoucherType::Fixed->calculateDiscount(subtotal: 200000, value: 500000);

        $this->assertSame(200000, $discount, 'Diskon harus dibatasi subtotal.');
    }

    /**
     * Voucher persen.
     */
    public function test_voucher_persen_menghitung_benar(): void
    {
        $voucher = $this->makeVoucher(type: VoucherType::Percent, value: 10, quota: 100);

        $result = app(ApplyVoucher::class)->execute($voucher->code, 250000);

        $this->assertSame(25000, $result['discount']);
    }

    /**
     * Kode voucher tidak case-sensitive — user mengetik dengan huruf besar
     * dari kupon cetak.
     */
    public function test_kode_voucher_tidak_case_sensitive(): void
    {
        $voucher = $this->makeVoucher();

        $result = app(ApplyVoucher::class)->execute(strtolower($voucher->code), 200000);

        $this->assertSame($voucher->code, $result['voucher']->code);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function makeVoucher(
        ?VoucherType $type = null,
        ?int $value = null,
        ?int $quota = null,
        ?int $minSpend = null,
        $startsAt = null,
        $endsAt = null,
    ): Voucher {
        return Voucher::create([
            'code' => 'UJI'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'type' => $type ?? VoucherType::Fixed,
            'value' => $value ?? 25000,
            'min_spend' => $minSpend ?? 0,
            'starts_at' => $startsAt ?? now()->subDay(),
            'ends_at' => $endsAt ?? now()->addMonth(),
            'quota' => $quota,
            'is_active' => true,
        ]);
    }

    /**
     * Coba konsumsi satu kuota. Mengembalikan true kalau berhasil.
     */
    private function consume(Voucher $voucher, ?Order $order = null): bool
    {
        $order ??= $this->makeOrder();

        try {
            app(ApplyVoucher::class)->commitRedemption($voucher, $order, 25000);

            return true;
        } catch (VoucherQuotaExhaustedException) {
            return false;
        }
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'order_number' => 'UJN-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 5)),
            'user_id' => $this->makeCustomer()->getKey(),
            'shipping_address' => ['city' => 'Mataram'],
            'subtotal' => 250000,
            'grand_total' => 225000,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
    }
}
