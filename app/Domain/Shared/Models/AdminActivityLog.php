<?php

namespace App\Domain\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log aktivitas admin — "siapa mengubah apa".
 *
 * PRD §3.10: "Log aktivitas admin (siapa mengubah apa) untuk akuntabilitas."
 * PRD §6A: "perubahan stok offline oleh admin tercatat dalam audit log."
 *
 * ===================================================================
 *  ATURAN KEAMANAN YANG WAJIB DIPATUHI
 * ===================================================================
 * Kolom `old_values` / `new_values` menyimpan nilai SEBELUM & SESUDAH perubahan.
 * Karena itu HANYA kolom non-sensitif yang boleh masuk.
 *
 * Dilarang keras menyimpan: password, password_confirmation, token,
 * remember_token, api_token. ActivityLogger sudah memblokir daftar itu —
 * kalau suatu saat ada kolom sensitif baru, daftar itu HARUS diperbarui
 * sekalian. Jangan andalkan 아시아 dass field itu ternyata tidak sensitif.
 */
class AdminActivityLog extends Model
{
    protected $table = 'admin_activity_logs';

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Daftar atribut yang TIDAK BOLEH pernah masuk log.
     *
     * Daftar ini dibaca ActivityLogger::scrub() saat menulis log.
     */
    public static function sensitiveAttributes(): array
    {
        return [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'remember_token',
            'api_token',
            'token',
            'secret',
            'access_token',
            'refresh_token',
        ];
    }
}
