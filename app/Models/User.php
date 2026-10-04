<?php

namespace App\Models;

use App\Domain\Cart\Models\Cart;
use App\Domain\Checkout\Models\Order;
use App\Domain\Shared\Models\Address;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Akun pengguna.
 *
 * DARI SATU TABEL UNTUK DUA JENIS AKUN, DAN ITU BISA MEMBAHAYAKAN:
 *
 * 1. Admin panel (Filament) — punya `role`. PRD §3.10: minimal role
 *    Superadmin & Staff Operasional dengan hak akses berbeda.
 *
 * 2. Pelanggan storefront — TIDAK punya role sama sekali. Otorisasi mereka
 *    ditentukan oleh token Sanctum, bukan nilai kolom `role`.
 *
 * Makna `role` bernilai null TIDAK berarti "pengguna biasa yang belum
 * terdaftar sebagai admin" — artinya "pelanggan". Jangan pernah memberi role ke
 * akun pelanggan.
 *
 * Keamanan password: cast `hashed` milik Laravel memakai bcrypt secara
 * default (PRD §3.12), dan BCRYPT_ROUNDS di .env.example = 12.
 */
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /**
     * HasApiTokens memberi method createToken() / currentAccessToken()
     * dari Laravel Sanctum.
     *
     * PRD §3.12: "Autentikasi berbasis token (JWT/Sanctum) untuk API
     * registered user; admin panel Filament menggunakan session-based auth
     * terpisah."
     *
     * Perhatikan: trait ini HANYA untuk API. Panel Filament memakai session,
     * bukan token. Jangan sampai logika panel admin ikut bergantung pada
     * token ini.
     */
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Laravel_hash otomatis mem-bcrypt password yang masih plain.
            // Jangan pernah menyimpan plain di kolom ini.
            'password' => 'hashed',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    /**
     * Alamat pengiriman. PRD §3.5: banyak alamat, satu ditandai default.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function cart(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // -----------------------------------------------------------------
    // Otorisasi admin
    // -----------------------------------------------------------------

    /**
     * Akun ini admin panel, bukan pelanggan?
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['superadmin', 'staff'], true);
    }

    /**
     * Superadmin punya akses ke manajemen role/user.
     *
     * PRD §3.10 AC: "Given Staff Operasional login (bukan Superadmin), When
     * mencoba akses menu manajemen role/user, Then akses ditolak (403)."
     *
     * Ini dicek di Policy, BUKAN hanya menyembunyikan menu di UI. Menyembunyikan
     * menu saja tidak cukup — endpoint-nya tetap harus menolak.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    /**
     * Gerbang pertama panel admin: pelanggan tidak boleh masuk sama sekali.
     *
     * Kenapa ini perlu di method (bukan hanya di policy): tanpa
     * `FilamentUser`, setiap user yang punya sesi di `/admin` dianggap punya
     * hak akses, lalu penolakan baru terjadi per halaman. Akibatnya akun
     * pelanggan bisa melihat halaman dashboard kosong lalu ditolak
     * setengah jalan — atau, pada resource tanpa policy, bisa melihat data.
     *
     * Menolak di pintu ini membuat `role = null` benar-benar berarti
     * "pelanggan", konsisten dengan docblock kelas.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    /**
     * Role untuk ditampilkan di UI admin.
     */
    public function roleLabel(): string
    {
        return match ($this->role) {
            'superadmin' => 'Superadmin',
            'staff' => 'Staf Operasional',
            default => 'Pelanggan',
        };
    }
}
