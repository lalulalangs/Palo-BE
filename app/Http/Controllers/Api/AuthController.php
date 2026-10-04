<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Cart\Services\CartResolver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Registrasi, login, logout, profil.
 *
 * PRD §3.5: "Registrasi & login via email/password (hash bcrypt/argon2)
 * (Asumsi); opsi verifikasi via OTP WhatsApp/email dapat ditambahkan sebagai
 * enhancement."
 *
 * PRD §3.12: password WAJIB di-hash (cast `hashed` di model User yang
 * memakai bcrypt). Password tidak pernah masuk response maupun log.
 *
 * Rate limit `login: 10/menit` didefinisikan di bootstrap/app.php
 * (PRD §3.12 AC).
 */
class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     *
     * Catatan: mockup lama punya halaman "Buat Akun" yang isinya cuma form
     * masuk. Frontend sudah memperbaiki; endpoint ini yang dipanggil tombol
     * "Buat Akun".
     */
    public function register(Request $request, MergeGuestCart $mergeCart, CartResolver $cartResolver): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Cast `hashed` di model otomatis mem-bcrypt. JANGAN panggil
            // Hash::make() di sini juga — itu akan menghasilkan hash ganda.
            'password' => $validated['password'],
            // role dibiarkan NULL: ini akun PELANGGAN, bukan admin.
            // Otorisasi pelanggan ditentukan token, bukan kolom role.
            'role' => null,
        ]);

        // PRD §3.4: gabung keranjang guest ke akun yang baru dibuat.
        $mergeCart->execute($user, $request->session()->getId());

        $token = $user->createToken('palorinjani-web')->plainTextToken;

        return response()->json([
            'message' => 'Akun berhasil dibuat.',
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Pesan sengaja SAMA untuk email tidak terdaftar maupun password salah.
        // Kalau berbeda, penyerang bisa memakai pesan itu untuk mengetahui
        // email mana yang terdaftar.
        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        $token = $user->createToken('palorinjani-web')->plainTextToken;

        // Merge keranjang guest kalau ada.
        app(MergeGuestCart::class)->execute($user, $request->session()->getId());

        return response()->json([
            'message' => 'Berhasil masuk.',
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Cabut HANYA token yang dipakai sekarang, bukan semua token user.
        // Kalau semua dicabut, login di HP lain ikut terputus.
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * Data user untuk response. TIDAK PERNAH menyertakan password.
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
