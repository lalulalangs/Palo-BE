<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `role` ke tabel users untuk RBAC Admin (PRD §3.10).
 *
 * PRD §3.10: "Role-based access control (Asumsi: minimal role Superadmin &
 * Staff Operasional dengan hak akses berbeda)."
 * AC §3.10: "Given Staff Operasional login (bukan Superadmin), When mencoba
 * akses menu manajemen role/user, Then akses ditolak (403 Forbidden)."
 *
 * PENTING: kolom ini hanya untuk ADMIN (backend Filament).
 * Pelanggan storefront TIDAK punya role — otorisasi mereka ditentukan oleh
 * token Sanctum, bukan oleh nilai kolom ini. Inilah yang sering dikacaukan.
 *
 * Password hashing: PRD §3.12 mensyaratkan bcrypt/argon2. Laravel sudah
 * memakai bcrypt secara default (lihat casts() di model User), dan konfigurasi
 * BCRYPT_ROUNDS default ke 12 di .env.example proyek ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // WAJIB NULLABLE, dan TIDAK boleh punya default.
            //
            // Kenapa: kolom ini hanya untuk ADMIN. Akun PELANGGAN harus punya
            // role = NULL. Kalau kolomnya NOT NULL dengan default 'staff',
            // maka setiap pelanggan yang dibuat lewat form registrasi akan
            // otomatis mendapat role 'staff' — dan karena isAdmin() memeriksa
            // nilai role, pelanggan bisa dianggap admin. Itu celah privilege
            // escalation.
            //
            // Karena itu: nullable, tanpa default. Pendaftaran pelanggan
            // (AuthController::register) mengirim role => null.
            $table->string('role', 32)->nullable()
                ->after('email')
                ->comment('superadmin | staff — khusus admin panel. NULL = pelanggan.');
        });

        // ------------------------------------------------------------------
        // order_status_histories sudah punya actor_id nullable, jadi user
        // yang dihapus tidak merusak audit trail. Tidak ada FK tambahan.
        // ------------------------------------------------------------------
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
