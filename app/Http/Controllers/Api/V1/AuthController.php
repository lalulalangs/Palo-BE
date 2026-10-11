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

        $guestToken = $request->input('guest_token')
            ?? $request->header('X-Guest-Token')
            ?? $request->cookie('guest_cart_id')
            ?? $request->cookie('guest_token');

        if (filled($guestToken)) {
            $this->cartService->mergeGuestCart($user, (string) $guestToken);
        }

        $token = $user->createToken('customer-auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Pendaftaran akun berhasil.',
            'data' => [
                'token' => $token,
                'user' => (new CustomerUserResource($user))->resolve(),
            ],
        ], 201);
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

        $token = $user->createToken('customer-auth-token')->plainTextToken;

        $guestToken = $request->input('guest_token')
            ?? $request->header('X-Guest-Token')
            ?? $request->cookie('guest_cart_id')
            ?? $request->cookie('guest_token');

        if (filled($guestToken)) {
            $this->cartService->mergeGuestCart($user, (string) $guestToken);
        }

        return response()->json([
            'success' => true,
            'message' => 'Berhasil masuk.',
            'data' => [
                'token' => $token,
                'user' => (new CustomerUserResource($user))->resolve(),
            ],
        ]);
    }

    /**
     * Mengambil profil customer yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diambil.',
            'data' => (new CustomerUserResource($request->user()))->resolve(),
        ]);
    }

    /**
     * Keluar dan mencabut token autentikasi saat ini.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Berhasil keluar.',
        ]);
    }
}
