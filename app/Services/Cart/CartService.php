<?php

namespace App\Services\Cart;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    private const GUEST_CACHE_PREFIX = 'cart:session:';

    private const GUEST_CACHE_TTL_DAYS = 30;

    /**
     * Resolve guest token from request or generate a new one.
     */
    public function resolveGuestToken(?Request $request = null, ?string $fallback = null): string
    {
        if (filled($fallback)) {
            return $fallback;
        }

        if ($request) {
            $token = $request->header('X-Guest-Token')
                ?? $request->cookie('guest_cart_id')
                ?? $request->cookie('guest_token')
                ?? $request->input('guest_token');

            if (filled($token) && is_string($token)) {
                return trim($token);
            }
        }

        return (string) Str::uuid();
    }

    /**
     * Get active cart for user or guest.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function getCart(?User $user, ?string $guestToken = null): array
    {
        if ($user) {
            return $this->getUserCart($user);
        }

        $token = $guestToken ?? (string) Str::uuid();

        return $this->getGuestCart($token);
    }

    /**
     * Add item to user or guest cart.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function addItem(?User $user, ?string &$guestToken, int $skuId, int $quantity = 1): array
    {
        /** @var Sku $sku */
        $sku = Sku::with(['product.thumbnail', 'variant'])->findOrFail($skuId);

        if (! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
            throw ValidationException::withMessages([
                'sku_id' => ['Produk atau varian ini saat ini tidak aktif atau tidak lagi tersedia.'],
            ]);
        }

        $availableStock = $sku->available_stock;

        if ($user) {
            $cart = Cart::firstOrCreate(
                ['user_id' => $user->id, 'status' => 'active']
            );

            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('sku_id', $skuId)
                ->first();

            $newQuantity = $cartItem ? ($cartItem->quantity + $quantity) : $quantity;

            if ($newQuantity > $availableStock) {
                throw ValidationException::withMessages([
                    'quantity' => ["Jumlah melebihi stok tersedia ({$availableStock})."],
                ]);
            }

            if ($cartItem) {
                $cartItem->update(['quantity' => $newQuantity]);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'sku_id' => $skuId,
                    'quantity' => $newQuantity,
                ]);
            }

            return $this->getUserCart($user);
        }

        $token = $guestToken ?? (string) Str::uuid();
        $guestToken = $token;

        $items = $this->getRawGuestItems($token);
        $existingIndex = null;

        foreach ($items as $index => $item) {
            if ((int) $item['sku_id'] === $skuId) {
                $existingIndex = $index;
                break;
            }
        }

        $currentQty = $existingIndex !== null ? (int) $items[$existingIndex]['quantity'] : 0;
        $newQuantity = $currentQty + $quantity;

        if ($newQuantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => ["Jumlah melebihi stok tersedia ({$availableStock})."],
            ]);
        }

        if ($existingIndex !== null) {
            $items[$existingIndex]['quantity'] = $newQuantity;
        } else {
            $items[] = [
                'sku_id' => $skuId,
                'quantity' => $newQuantity,
            ];
        }

        $this->saveRawGuestItems($token, $items);

        return $this->formatCart($items, $token);
    }

    /**
     * Update item quantity in user or guest cart.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function updateItem(?User $user, ?string $guestToken, int $skuId, int $quantity): array
    {
        if ($quantity <= 0) {
            return $this->removeItem($user, $guestToken, $skuId);
        }

        /** @var Sku $sku */
        $sku = Sku::with('product')->findOrFail($skuId);
        $availableStock = $sku->available_stock;

        if (! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
            throw ValidationException::withMessages([
                'sku_id' => ['Produk atau varian ini saat ini tidak aktif atau tidak lagi tersedia.'],
            ]);
        }

        if ($quantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => ["Jumlah melebihi stok tersedia ({$availableStock})."],
            ]);
        }

        if ($user) {
            $cart = Cart::where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            $affected = 0;

            if ($cart) {
                $affected = CartItem::where('cart_id', $cart->id)
                    ->where('sku_id', $skuId)
                    ->update(['quantity' => $quantity]);
            }

            if ($affected === 0) {
                throw ValidationException::withMessages([
                    'sku_id' => ['Item tidak ditemukan di keranjang.'],
                ]);
            }

            return $this->getUserCart($user);
        }

        $token = $guestToken ?? (string) Str::uuid();
        $items = $this->getRawGuestItems($token);
        $found = false;

        foreach ($items as $index => $item) {
            if ((int) $item['sku_id'] === $skuId) {
                $items[$index]['quantity'] = $quantity;
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw ValidationException::withMessages([
                'sku_id' => ['Item tidak ditemukan di keranjang.'],
            ]);
        }

        $this->saveRawGuestItems($token, $items);

        return $this->formatCart($items, $token);
    }

    /**
     * Remove an item from user or guest cart.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function removeItem(?User $user, ?string $guestToken, int $skuId): array
    {
        if ($user) {
            $cart = Cart::where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($cart) {
                CartItem::where('cart_id', $cart->id)
                    ->where('sku_id', $skuId)
                    ->delete();
            }

            return $this->getUserCart($user);
        }

        $token = $guestToken ?? (string) Str::uuid();
        $items = $this->getRawGuestItems($token);

        $filtered = array_values(array_filter(
            $items,
            fn (array $item) => (int) $item['sku_id'] !== $skuId
        ));

        $this->saveRawGuestItems($token, $filtered);

        return $this->formatCart($filtered, $token);
    }

    /**
     * Merge guest cart into registered user cart upon login or explicit merge request.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function mergeGuestCart(User $user, string $guestToken): array
    {
        $guestItems = $this->getRawGuestItems($guestToken);

        if (empty($guestItems)) {
            return $this->getUserCart($user);
        }

        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active']
        );

        foreach ($guestItems as $guestItem) {
            $skuId = (int) $guestItem['sku_id'];
            $quantity = (int) $guestItem['quantity'];

            $sku = Sku::with('product')->find($skuId);
            if (! $sku || ! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
                continue;
            }

            $availableStock = $sku->available_stock;
            if ($availableStock <= 0) {
                continue;
            }

            $existingItem = CartItem::where('cart_id', $cart->id)
                ->where('sku_id', $skuId)
                ->first();

            if ($existingItem) {
                $mergedQuantity = min($availableStock, $existingItem->quantity + $quantity);
                $existingItem->update(['quantity' => $mergedQuantity]);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'sku_id' => $skuId,
                    'quantity' => min($availableStock, $quantity),
                ]);
            }
        }

        // Clear guest session cart from cache
        $this->clearGuestCart($guestToken);

        $cart = $this->getUserCart($user);

        // Rotasi token: token lama sudah tidak berlaku, browser diberi token baru
        // agar token yang mungkin bocor tidak dapat dipakai ulang.
        $cart['guest_token'] = (string) Str::uuid();

        return $cart;
    }

    /**
     * Clear all items in active cart.
     */
    public function clearCart(?User $user, ?string $guestToken = null): void
    {
        if ($user) {
            $cart = Cart::where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($cart) {
                $cart->items()->delete();
            }

            return;
        }

        if ($guestToken) {
            $this->clearGuestCart($guestToken);
        }
    }

    /**
     * Format cart payload into uniform schema.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $rawItems
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    public function formatCart(array $rawItems, ?string $guestToken = null): array
    {
        if (empty($rawItems)) {
            return [
                'items' => [],
                'summary' => [
                    'item_count' => 0,
                    'total_quantity' => 0,
                    'subtotal' => 0,
                    'subtotal_display' => Number::currency(0, 'IDR', 'id'),
                ],
                'guest_token' => $guestToken,
            ];
        }

        $skuIds = array_column($rawItems, 'sku_id');
        $skus = Sku::with(['variant', 'product.thumbnail', 'product.media'])
            ->whereIn('id', $skuIds)
            ->get()
            ->keyBy('id');

        // Satu query untuk seluruh SKU di keranjang (menghindari N+1 dari
        // accessor Sku::available_stock yang menembak DB per baris).
        $activeReservations = OrderItem::query()
            ->whereIn('sku_id', $skuIds)
            ->whereHas('order', function (Builder $query): void {
                $query->where('status', OrderStatus::Pending->value)
                    ->where('stock_locked_until', '>', now());
            })
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(quantity) as total_reserved')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->sku_id => (int) $row->total_reserved]);

        $formattedItems = [];
        $totalSubtotal = 0;
        $totalQuantity = 0;

        foreach ($rawItems as $raw) {
            $skuId = (int) $raw['sku_id'];
            $quantity = (int) $raw['quantity'];

            /** @var Sku|null $sku */
            $sku = $skus->get($skuId);

            if (! $sku) {
                continue;
            }

            $product = $sku->product;
            $variant = $sku->variant;
            $unitPrice = $sku->effective_price;
            $subtotal = $unitPrice * $quantity;
            $isUnavailable = ($product === null) || ! $sku->is_active || ! $product->is_active;
            $reserved = (int) ($activeReservations->get($skuId) ?? 0);
            $availableStock = $isUnavailable ? 0 : max(0, $sku->stock - $reserved);
            $isOutOfStock = $isUnavailable || ($availableStock <= 0);
            $productName = $product?->name ?? 'Produk Tidak Lagi Tersedia';

            $thumbnailUrl = null;
            if ($product) {
                if ($product->thumbnail) {
                    $thumbnailUrl = $product->thumbnail->url;
                } elseif ($product->media && $product->media->isNotEmpty()) {
                    $thumbnailUrl = $product->media->first()->url;
                }
            }

            if ($thumbnailUrl && ! Str::startsWith($thumbnailUrl, ['http://', 'https://'])) {
                $thumbnailUrl = Storage::disk('public')->url($thumbnailUrl);
            }

            $formattedItems[] = [
                'sku_id' => $sku->id,
                'product_id' => $product?->id,
                'product_name' => $productName,
                'product_slug' => $product?->slug,
                'variant_name' => $variant?->name,
                'sku_code' => $sku->sku_code,
                'unit_price' => $unitPrice,
                'unit_price_display' => Number::currency($unitPrice, 'IDR', 'id'),
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'subtotal_display' => Number::currency($subtotal, 'IDR', 'id'),
                'available_stock' => $availableStock,
                'is_out_of_stock' => $isOutOfStock,
                'image' => $thumbnailUrl,
            ];

            $totalSubtotal += $subtotal;
            $totalQuantity += $quantity;
        }

        return [
            'items' => $formattedItems,
            'summary' => [
                'item_count' => count($formattedItems),
                'total_quantity' => $totalQuantity,
                'subtotal' => $totalSubtotal,
                'subtotal_display' => Number::currency($totalSubtotal, 'IDR', 'id'),
            ],
            'guest_token' => $guestToken,
        ];
    }

    /**
     * Get user cart from database.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    private function getUserCart(User $user): array
    {
        $cart = Cart::with('items')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $cart) {
            return $this->formatCart([], null);
        }

        $rawItems = $cart->items->map(fn (CartItem $item) => [
            'sku_id' => $item->sku_id,
            'quantity' => $item->quantity,
        ])->toArray();

        return $this->formatCart($rawItems, null);
    }

    /**
     * Get guest cart from cache.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, guest_token: string|null}
     */
    private function getGuestCart(string $guestToken): array
    {
        $rawItems = $this->getRawGuestItems($guestToken);

        return $this->formatCart($rawItems, $guestToken);
    }

    /**
     * Read raw item list for guest session from cache.
     *
     * @return array<int, array{sku_id: int, quantity: int}>
     */
    public function getRawGuestItems(string $guestToken): array
    {
        return Cache::get($this->guestCacheKey($guestToken), []);
    }

    /**
     * Cache key memakai hash agar token mentah tidak pernah menjadi key cache
     * (mencegah key injection dan kebocoran token lewat dump cache).
     */
    private function guestCacheKey(string $guestToken): string
    {
        return self::GUEST_CACHE_PREFIX.hash('sha256', $guestToken);
    }

    /**
     * Persist raw item list for guest session into cache.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     */
    private function saveRawGuestItems(string $guestToken, array $items): void
    {
        Cache::put($this->guestCacheKey($guestToken), $items, now()->addDays(self::GUEST_CACHE_TTL_DAYS));
    }

    /**
     * Remove guest cart from cache.
     */
    private function clearGuestCart(string $guestToken): void
    {
        Cache::forget($this->guestCacheKey($guestToken));
    }
}
