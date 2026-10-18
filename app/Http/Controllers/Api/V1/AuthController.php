<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginCustomerRequest;
use App\Http\Requests\Api\V1\RegisterCustomerRequest;
use App\Http\Resources\Api\V1\CustomerUserResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * Pendaftaran akun baru customer.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => strtolower($request->validated('email')),
            'password_hash' => Hash::make($request->validated('password')),
            'phone' => $request->validated('phone'),
        ]);

        $rotatedGuestToken = $this->mergeGuestCartIfPresent($request, $user);

        $response = $this->respond(
            [
                'token' => $user->createToken('customer-auth-token')->plainTextToken,
                'user' => (new CustomerUserResource($user))->resolve(),
            ],
            'Pendaftaran akun berhasil.',
            status: 201,
        );

        return $this->withRotatedGuestTokenCookie($response, $rotatedGuestToken);
    }

    /**
     * Masuk ke akun customer.
     */
    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $user = User::where('email', strtolower($request->validated('email')))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat surel atau kata sandi tidak cocok.',
                'errors' => [
                    'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
                ],
            ], 401);
        }

        $rotatedGuestToken = $this->mergeGuestCartIfPresent($request, $user);

        $response = $this->respond(
            [
                'token' => $user->createToken('customer-auth-token')->plainTextToken,
                'user' => (new CustomerUserResource($user))->resolve(),
            ],
            'Berhasil masuk.',
        );

        return $this->withRotatedGuestTokenCookie($response, $rotatedGuestToken);
    }

    /**
     * Mengambil profil customer yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->respond(
            (new CustomerUserResource($request->user()))->resolve(),
            'Profil berhasil diambil.',
        );
    }

    /**
     * Keluar dan mencabut token autentikasi saat ini.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->respond(null, 'Berhasil keluar.');
    }

    /**
     * Gabungkan keranjang tamu bila token guest dikirim, lalu kembalikan token
     * guest baru agar token lama tidak dapat dipakai ulang.
     */
    private function mergeGuestCartIfPresent(Request $request, User $user): ?string
    {
        $guestToken = $request->input('guest_token')
            ?? $request->header('X-Guest-Token')
            ?? $request->cookie('guest_cart_id')
            ?? $request->cookie('guest_token');

        if (! is_string($guestToken) || ! filled($guestToken)) {
            return null;
        }

        $cart = $this->cartService->mergeGuestCart($user, $guestToken);

        return filled($cart['guest_token'] ?? null) ? (string) $cart['guest_token'] : null;
    }

    private function withRotatedGuestTokenCookie(JsonResponse $response, ?string $token): JsonResponse
    {
        if (filled($token)) {
            $response->withCookie($this->guestCartCookie((string) $token));
        }

        return $response;
    }
}
