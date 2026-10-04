<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Mencatat aktivitas admin untuk keperluan akuntabilitas.
 *
 * PRD §3.10: "Log aktivitas admin (siapa mengubah apa) untuk akuntabilitas."
 * PRD §6A: "perubahan stok offline oleh admin tercatat dalam audit log."
 *
 * ===================================================================
 *  BERAPA KALI INI DIBACA SEBAGAI MASALAH "LOG MEMBOCORKAN RAHASIA"
 * ===================================================================
 * `old_values` dan `new_values` menyimpan nilai SEBELUM & SESUDAH. Kalau
 * form UserResource mengirim `password`, maka plaintext-nya akan tersimpan
 * permanen di `admin_activity_logs` — persis hal yang dilarang PRD §3.12
 * ("tanpa mengekspos data sensitif").
 *
 * Karena itu SEMUA penulisan wajib lewat `scrub()`. Dan `scrub()` memakai
 * `AdminActivityLog::sensitiveAttributes()` sebagai sumber kebenaran —
 * kalau suatu saat ada kolom sensitif baru, cukup tambahkan di sana, bukan
 * di setiap pemanggil.
 *
 * CATATAN ARSITEKTUR: file ini berada di `Domain/`, jadi ia tidak boleh
 * meng-import apa pun dari `Filament/`. Yang jadi adapter adalah pemanggilnya
 * (Resource, Page, Widget).
 */
class LogAdminActivity
{
    /**
     * Pola nama atribut tambahan yang dianggap sensitif, di luar daftar
     * `AdminActivityLog::sensitiveAttributes()`.
     *
     * Dipakai sebagai jaring pengaman kedua: daftar resmi bisa tertinggal
     * kalau ada kolom bernama `user_password` atau `api_secret` yang tidak
     * dijaga oleh siapa pun yang menambahkannya.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_PATTERNS = [
        'password',
        'passwd',
        'token',
        'secret',
        'cvv',
        'cvc',
        'card_number',
        'authorization',
    ];

    public function created(Model $subject, array $newValues = []): AdminActivityLog
    {
        return $this->log('created', $subject, null, $newValues);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function updated(Model $subject, array $oldValues, array $newValues): AdminActivityLog
    {
        return $this->log('updated', $subject, $oldValues, $newValues);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     */
    public function deleted(Model $subject, array $oldValues = []): AdminActivityLog
    {
        return $this->log('deleted', $subject, $oldValues, null);
    }

    /**
     * Catat aksi non-CRUD (mis. "terbitkan", "sesuaikan stok", "ekspor").
     *
     * Dipakai kalau aksinya tidak terlihat dari perubahan baris — misalnya
     * publish produk, yang secara teknis hanya mengubah `status`.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function custom(string $action, ?Model $subject = null, ?array $oldValues = null, ?array $newValues = null): AdminActivityLog
    {
        return $this->log(Str::limit($action, 64, ''), $subject, $oldValues, $newValues);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(string $action, ?Model $subject, ?array $oldValues, ?array $newValues): AdminActivityLog
    {
        $scrubbedOld = $oldValues === null ? null : $this->scrub($oldValues);
        $scrubbedNew = $newValues === null ? null : $this->scrub($newValues);

        return AdminActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            // Kolom ini diisi dengan nama class singkat ("Product", "Sku", ...)
            // supaya laporan log enak dibaca dan tidak leak struktur namespace.
            'subject_type' => $subject === null ? 'System' : class_basename($subject::class),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'old_values' => ($scrubbedOld === []) ? null : $scrubbedOld,
            'new_values' => ($scrubbedNew === []) ? null : $scrubbedNew,
            'ip_address' => $this->currentIpAddress(),
            'user_agent' => $this->currentUserAgent(),
        ]);
    }

    /**
     * Buang nilai sensitif dari array sebelum disimpan.
     *
     * Mengembalikan salinan — array asal tidak dimodifikasi, supaya pemanggil
     * tidak ikut kehilangan field yang memang ia perlukan (mis. tetap butuh
     * `password` untuk disimpan ke tabel `users`).
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function scrub(array $values): array
    {
        $blocked = array_map('mb_strtolower', AdminActivityLog::sensitiveAttributes());

        $clean = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey((string) $key, $blocked)) {
                // Jangan simpan nilai apa pun — termasuk nilai redacted,
                // karena "ada/tidaknya key" saja sudah membocorkan informasi.
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value);

                continue;
            }

            if ($value instanceof \BackedEnum) {
                $clean[$key] = $value->value;

                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $clean[$key] = $value->format('c');

                continue;
            }

            // Objek, resource, dan closure tidak bisa di-encode jadi JSON dengan
            // aman. Membuangnya lebih baik daripada membiarkan json_encode gagal
            // dan menggagalkan seluruh operasi bisnis yang sedang berjalan.
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Salinan nilai saat ini dari sebuah record, untuk dibandingkan nanti.
     *
     * Panggil SEBELUM `$record->save()`. Setelah `save()` Laravel menyinkronkan
     * `original` dengan nilai baru, sehingga nilai "sebelum" hilang.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Model $record): array
    {
        return $this->scrub($record->getAttributes());
    }

    /**
     * Bandingkan nilai sebelum dan sesudah, sisakan hanya yang benar-benar beda.
     *
     * Ini menjaga `admin_activity_logs` tetap kecil dan bisa dibaca. Log yang
     * memuat seluruh baris setiap kali satu field diubah tidak berguna.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $key => $value) {
            $oldValue = $before[$key] ?? null;

            if ($this->normalise($oldValue) === $this->normalise($value)) {
                continue;
            }

            $diff[$key] = [
                'from' => $oldValue,
                'to' => $value,
            ];
        }

        return $this->scrub($diff);
    }

    /**
     * Normalisasi ringan supaya perbandingan tidak menghasilkan diff palsu.
     *
     * Tanpa ini, mengubah 100000 menjadi "100000" (string dari form) akan
     * tercatat sebagai perubahan padahal tidak ada. Longgar untuk angka,
     * ketat untuk yang lain.
     */
    private function normalise(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $blocked
     */
    private function isSensitiveKey(string $key, array $blocked): bool
    {
        $normalised = mb_strtolower($key);

        if (in_array($normalised, $blocked, true)) {
            return true;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($normalised, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function currentIpAddress(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        return request()->ip();
    }

    private function currentUserAgent(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $userAgent = request()->userAgent();

        // Kolom `user_agent` dibatasi 512 karakter di migration.
        return filled($userAgent) ? mb_substr($userAgent, 0, 512) : null;
    }

    /**
     * Aktor saat ini, untuk keperluan tampilan di halaman audit.
     */
    public function currentActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
