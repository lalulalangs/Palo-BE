<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * CRUD alamat pengiriman.
 *
 * PRD §3.5: "Manajemen banyak alamat pengiriman (CRUD), dengan satu alamat
 * ditandai default."
 *
 * ===================================================================
 *  HUBUNGAN DENGAN ORDER — BACA DULU
 * ===================================================================
 * Alamat di sini adalah TEMPLATE yang bisa diedit dan dihapus. Saat order
 * dibuat, isinya disalin ke `orders.shipping_address` sebagai snapshot
 * JSON (lihat CreateOrder::buildAddressSnapshot()).
 *
 * Jadi menghapus alamat TIDAK akan merusak riwayat pesanan. Itulah yang
 * PRD §3.5 AC tunaNb серед。
 *
 * Sebaliknya, karena snapshot-lah yang jadi acuan, mengubah alamat di sini
 * tidak mengubah order lama — persis seperti yang AC §3.5 minta.
 */
class AddressController extends Controller
{
    /**
     * GET /api/v1/addresses
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', $request->user()->getKey())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (Address $a) => $this->payload($a))
            ->values();

        return response()->json(['data' => $addresses]);
    }

    /**
     * POST /api/v1/addresses
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $address = DB::transaction(function () use ($request, $validated) {
            $isFirst = Address::where('user_id', $request->user()->getKey())->doesntExist();

            $address = Address::create(array_merge($validated, [
                'user_id' => $request->user()->getKey(),
                // Alamat pertama otomatis jadi default. Kalau tidak, checkout
                // akan selalu kosong sampai user memilih satu.
                'is_default' => $isFirst,
            ]));

            if ($isFirst) {
                $address->markAsDefault();
            }

            return $address;
        });

        return response()->json([
            'message' => 'Alamat disimpan.',
            'data' => $this->payload($address),
        ], 201);
    }

    /**
     * PUT /api/v1/addresses/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $address = $this->findOwned($request, $id);

        $validated = $request->validate($this->rules(), $this->messages());

        // `is_default` TIDAK bisa diubah lewat update biasa. Untuk menjadikan
        // default, ada endpoint khusus `setDefault()` supaya tidak ada dua
        // permintaan yang saling bertabrakan.
        unset($validated['is_default']);

        $address->update($validated);

        return response()->json([
            'message' => 'Alamat diperbarui.',
            'data' => $this->payload($address->refresh()),
        ]);
    }

    /**
     * DELETE /api/v1/addresses/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = $this->findOwned($request, $id);

        $wasDefault = $address->is_default;
        $userId = $address->user_id;

        DB::transaction(function () use ($address, $wasDefault, $userId) {
            $address->delete();

            // Kalau alamat yang dihapus adalah default, pindahkan status
            // default ke alamat lain supaya user tidak kehilangan default.
            if ($wasDefault) {
                Address::where('user_id', $userId)
                    ->orderBy('id')
                    ->first()?->markAsDefault();
            }
        });

        return response()->json(['message' => 'Alamat dihapus.']);
    }

    /**
     * POST /api/v1/addresses/{id}/default
     *
     * Endpoint terpisah supaya perpindahan default tidak bisa gagal setengah
     * akibat dua request bersamaan (Address::markAsDefault() membungkus
     * operasinya dalam satu transaksi).
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $address = $this->findOwned($request, $id);
        $address->markAsDefault();

        return response()->json([
            'message' => 'Alamat utama diperbarui.',
            'data' => $this->payload($address->refresh()),
        ]);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /**
     * Cari alamat berdasarkan id DAN pemiliknya.
     *
     * Wajib difilter user_id. Kalau tidak, pelanggan bisa menebak id dan
     * membaca alamat orang lain — kebocoran data pribadi.
     */
    private function findOwned(Request $request, int $id): Address
    {
        return Address::where('user_id', $request->user()->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'province' => ['required', 'string', 'max:100'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:100'],
            // Wajib: API kurir hanya bisa menghitung ongkir dari kode kota.
            'city_code' => ['required', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'subdistrict' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'street' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'city_code.required' => 'Kota wajib dipilih dari daftar agar ongkir bisa dihitung.',
            'street.required' => 'Alamat lengkap wajib diisi.',
            'phone.required' => 'Nomor telepon wajib diisi.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Address $address): array
    {
        return [
            'id' => $address->getKey(),
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'province_code' => $address->province_code,
            'city' => $address->city,
            'city_code' => $address->city_code,
            'district' => $address->district,
            'subdistrict' => $address->subdistrict,
            'postal_code' => $address->postal_code,
            'street' => $address->street,
            'notes' => $address->notes,
            'is_default' => (bool) $address->is_default,
            'full_address' => $address->fullAddress(),
        ];
    }
}
