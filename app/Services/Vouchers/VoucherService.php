<?php

namespace App\Services\Vouchers;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /**
     * Validasi kode voucher terhadap subtotal belanja tanpa penguncian database baris.
     * Digunakan oleh endpoint preview/apply voucher.
     *
     * @throws ValidationException
     */
    public function validate(string $code, float $subtotal, ?User $user = null, string $fieldKey = 'code'): VoucherValidationResult
    {
        return $this->performValidation($code, $subtotal, lock: false, user: $user, fieldKey: $fieldKey);
    }

    /**
     * Validasi kode voucher dengan lockForUpdate untuk proteksi race condition saat checkout order final.
     *
     * @throws ValidationException
     */
    public function validateAndLock(string $code, float $subtotal, ?User $user = null, string $fieldKey = 'voucher_code'): VoucherValidationResult
    {
        return $this->performValidation($code, $subtotal, lock: true, user: $user, fieldKey: $fieldKey);
    }

    /**
     * Eksekusi validasi aturan voucher secara terpusat.
     *
     * @throws ValidationException
     */
    protected function performValidation(
        string $code,
        float $subtotal,
        bool $lock = false,
        ?User $user = null,
        string $fieldKey = 'voucher_code'
    ): VoucherValidationResult {
        $normalizedCode = strtoupper(trim($code));

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                $fieldKey => ['Kode voucher tidak boleh kosong.'],
            ]);
        }

        $query = Voucher::query()->where('code', $normalizedCode);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var Voucher|null $voucher */
        $voucher = $query->first();

        if (! $voucher) {
            throw ValidationException::withMessages([
                $fieldKey => ['Kode voucher tidak ditemukan atau tidak valid.'],
            ]);
        }

        if (! $voucher->is_active) {
            throw ValidationException::withMessages([
                $fieldKey => ['Voucher sedang tidak aktif.'],
            ]);
        }

        if (! $voucher->hasStarted()) {
            $formattedStart = $voucher->start_date ? $voucher->start_date->format('d/m/Y') : '-';
            throw ValidationException::withMessages([
                $fieldKey => ["Voucher belum dapat digunakan (berlaku mulai {$formattedStart})."],
            ]);
        }

        if ($voucher->isExpired()) {
            throw ValidationException::withMessages([
                $fieldKey => ['Voucher telah kedaluwarsa.'],
            ]);
        }

        if (! $voucher->hasQuotaRemaining()) {
            throw ValidationException::withMessages([
                $fieldKey => ['Kuota pemakaian voucher telah habis.'],
            ]);
        }

        if ($voucher->min_purchase > 0 && $subtotal < (float) $voucher->min_purchase) {
            $minFormatted = number_format((float) $voucher->min_purchase, 0, ',', '.');
            throw ValidationException::withMessages([
                $fieldKey => ["Syarat belanja minimum Rp {$minFormatted} belum terpenuhi."],
            ]);
        }

        $discountAmount = $voucher->calculateDiscount($subtotal);
        $finalSubtotal = max(0.0, $subtotal - $discountAmount);

        return new VoucherValidationResult(
            voucher: $voucher,
            discountAmount: $discountAmount,
            subtotal: $subtotal,
            finalSubtotal: $finalSubtotal,
        );
    }

    /**
     * Hitung nilai diskon tanpa melempar exception.
     */
    public function calculateDiscount(Voucher $voucher, float $subtotal): float
    {
        return $voucher->calculateDiscount($subtotal);
    }

    /**
     * Tingkatkan counter pemakaian voucher secara atomik.
     */
    public function incrementUsage(Voucher $voucher): void
    {
        $voucher->increment('used_count');
    }
}
