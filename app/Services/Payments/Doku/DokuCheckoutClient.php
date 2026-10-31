<?php

namespace App\Services\Payments\Doku;

use App\Contracts\PaymentGatewayContract;
use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Klien DOKU Checkout (hosted payment page).
 *
 * Alur: `POST /checkout/v1/payment` → customer diarahkan ke `response.payment.url`.
 * Status akhir diterima lewat HTTP Notification (lihat DokuWebhookService) atau
 * Check Status API `GET /orders/v1/status/{invoice_number}`.
 *
 * Referensi: https://developers.doku.com/accept-payments/doku-checkout/integration-guide/backend-integration
 */
class DokuCheckoutClient implements PaymentGatewayContract
{
    private const CHECKOUT_TARGET = '/checkout/v1/payment';

    private const STATUS_TARGET = '/orders/v1/status';

    /**
     * Pemetaan metode pilihan customer ke `payment.payment_method_types`.
     *
     * Nilai diambil dari daftar resmi DOKU:
     * https://developers.doku.com/accept-payments/doku-checkout/configuration/supported-payment-methods
     *
     * @var array<string, array<int, string>>
     */
    private const PAYMENT_METHOD_TYPES = [
        'qris' => ['QRIS'],
        'virtual_account' => [
            'VIRTUAL_ACCOUNT_BCA',
            'VIRTUAL_ACCOUNT_BRI',
            'VIRTUAL_ACCOUNT_BNI',
            'VIRTUAL_ACCOUNT_BANK_MANDIRI',
            'VIRTUAL_ACCOUNT_BANK_PERMATA',
            'VIRTUAL_ACCOUNT_BANK_CIMB',
            'VIRTUAL_ACCOUNT_DOKU',
        ],
        'bank_transfer' => [
            'VIRTUAL_ACCOUNT_BCA',
            'VIRTUAL_ACCOUNT_BRI',
            'VIRTUAL_ACCOUNT_BNI',
            'VIRTUAL_ACCOUNT_BANK_MANDIRI',
            'VIRTUAL_ACCOUNT_BANK_PERMATA',
            'VIRTUAL_ACCOUNT_BANK_CIMB',
            'VIRTUAL_ACCOUNT_DOKU',
        ],
        'ewallet' => [
            'EMONEY_OVO',
            'EMONEY_DANA',
            'EMONEY_SHOPEE_PAY',
            'EMONEY_DOKU',
            'EMONEY_LINKAJA',
        ],
        'credit_card' => ['CREDIT_CARD'],
    ];

    public function __construct(private readonly DokuSignature $signature) {}

    public function name(): string
    {
        return 'doku';
    }

    public function isConfigured(): bool
    {
        return filled($this->baseUrl())
            && filled(config('doku.client_id'))
            && filled(config('doku.secret_key'));
    }

    /**
     * Buat halaman pembayaran DOKU untuk order, lalu kembalikan instruksi
     * pembayaran netral (tanpa QR/VA karangan: semuanya berasal dari DOKU).
     */
    public function createPayment(Order $order, ?string $paymentMethod = null): array
    {
        $this->ensureConfigured();

        $amount = (int) round((float) $order->total);
        $requestedMethod = $paymentMethod ?? (string) $order->payment_method;

        if ($amount < 1) {
            throw PaymentGatewayException::invalidAmount();
        }

        $payload = [
            'order' => array_filter([
                'amount' => $amount,
                'invoice_number' => $order->order_number,
                'currency' => 'IDR',
                'callback_url' => $this->callbackUrl($order),
                'auto_redirect' => true,
                'line_items' => $this->buildLineItems($order, $amount),
            ], static fn (mixed $value): bool => $value !== null),
            'payment' => array_filter([
                'payment_due_date' => $this->paymentWindowMinutes(),
                'payment_method_types' => $this->methodTypes($requestedMethod),
            ], static fn (mixed $value): bool => $value !== null),
        ];

        $customer = $this->buildCustomer($order);

        if ($customer !== []) {
            $payload['customer'] = $customer;
        }

        $data = $this->post(self::CHECKOUT_TARGET, $payload, $order->order_number);

        $paymentResponse = $data['response']['payment'] ?? [];
        $orderResponse = $data['response']['order'] ?? [];
        $paymentUrl = (string) ($paymentResponse['url'] ?? '');

        if ($paymentUrl === '') {
            throw PaymentGatewayException::invalidResponse($order->order_number);
        }

        $expiresAt = $this->parseExpiredDate($paymentResponse['expired_date'] ?? null)
            ?? $order->stock_locked_until;

        $reference = (string) ($orderResponse['invoice_number'] ?? $order->order_number);

        return [
            'gateway' => $this->name(),
            'method' => $requestedMethod,
            'reference' => $reference,
            'payment_url' => $paymentUrl,
            'expires_at' => $expiresAt?->toIso8601String(),
            'instructions' => [
                'method' => $requestedMethod,
                'gateway' => $this->name(),
                'payment_url' => $paymentUrl,
                'reference' => $reference,
                'session_id' => $orderResponse['session_id'] ?? null,
                'qr_string' => null,
                'va_number' => null,
                'bank_name' => null,
                'instructions_text' => 'Selesaikan pembayaran melalui halaman pembayaran DOKU.',
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
            'raw_response' => $data,
        ];
    }

    /**
     * Verifikasi signature notifikasi DOKU.
     *
     * @param  array{client_id?: string, request_id?: string, request_timestamp?: string, signature?: string}  $headers
     */
    public function verifySignature(string $payload, array $headers, string $requestTarget): bool
    {
        return $this->signature->verify($payload, $headers, $requestTarget);
    }

    /**
     * Urai payload notifikasi DOKU menjadi event netral.
     *
     * @return array{event_id: string, order_number: string, status: string, amount: int, channel: string|null, service: string|null, raw: array<string, mixed>}
     */
    public function parseWebhook(string $payload): array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PaymentGatewayException::invalidWebhook();
        }

        if (! is_array($data)) {
            throw PaymentGatewayException::invalidWebhook();
        }

        $invoiceNumber = (string) ($data['order']['invoice_number'] ?? '');
        $status = strtoupper((string) ($data['transaction']['status'] ?? ''));

        if ($invoiceNumber === '' || $status === '') {
            throw PaymentGatewayException::invalidWebhook();
        }

        // Kombinasi invoice + status + tanggal transaksi membuat retry DOKU
        // untuk event yang sama menghasilkan id yang sama (idempoten).
        $eventId = implode(':', array_filter([
            'doku',
            $invoiceNumber,
            $status,
            (string) ($data['transaction']['date'] ?? ''),
        ], static fn (string $part): bool => $part !== ''));

        return [
            'event_id' => $eventId,
            'order_number' => $invoiceNumber,
            'status' => $status,
            'amount' => (int) round((float) ($data['order']['amount'] ?? 0)),
            'channel' => isset($data['channel']['id']) ? (string) $data['channel']['id'] : null,
            'service' => isset($data['service']['id']) ? (string) $data['service']['id'] : null,
            'raw' => $data,
        ];
    }

    /**
     * Cek status transaksi ke Check Status API DOKU (untuk rekonsiliasi).
     *
     * @return array{status: string, amount: int, channel: string|null, raw: array<string, mixed>}
     */
    public function checkStatus(string $invoiceNumber): array
    {
        $this->ensureConfigured();

        $target = self::STATUS_TARGET.'/'.rawurlencode($invoiceNumber);
        $requestId = (string) Str::uuid();
        $timestamp = $this->timestamp();

        try {
            $response = Http::connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->withHeaders([
                    'Client-Id' => (string) config('doku.client_id'),
                    'Request-Id' => $requestId,
                    'Request-Timestamp' => $timestamp,
                    'Signature' => $this->signature->sign(
                        (string) config('doku.client_id'),
                        $requestId,
                        $timestamp,
                        $target,
                        null,
                    ),
                ])
                ->get($this->baseUrl().$target);
        } catch (ConnectionException $exception) {
            Log::warning('payment.doku.status_unreachable', [
                'invoice_number' => $invoiceNumber,
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ]);

            throw PaymentGatewayException::unreachable();
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            Log::warning('payment.doku.status_failed', [
                'invoice_number' => $invoiceNumber,
                'request_id' => $requestId,
                'http_status' => $response->status(),
            ]);

            throw PaymentGatewayException::rejected($invoiceNumber);
        }

        // Sebagian respons Check Status dibungkus `response`, sebagian tidak.
        if (is_array($data['response'] ?? null)) {
            $data = $data['response'];
        }

        return [
            'status' => strtoupper((string) ($data['transaction']['status'] ?? '')),
            'amount' => (int) round((float) ($data['order']['amount'] ?? 0)),
            'channel' => isset($data['channel']['id']) ? (string) $data['channel']['id'] : null,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $target, array $payload, string $invoiceNumber): array
    {
        try {
            $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::error('payment.doku.payload_encode_failed', [
                'invoice_number' => $invoiceNumber,
                'error' => $exception->getMessage(),
            ]);

            throw PaymentGatewayException::invalidResponse($invoiceNumber);
        }

        $requestId = (string) Str::uuid();
        $timestamp = $this->timestamp();
        $clientId = (string) config('doku.client_id');

        try {
            $response = Http::connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->withHeaders([
                    'Client-Id' => $clientId,
                    'Request-Id' => $requestId,
                    'Request-Timestamp' => $timestamp,
                    'Signature' => $this->signature->sign($clientId, $requestId, $timestamp, $target, $rawBody),
                ])
                ->withBody($rawBody, 'application/json')
                ->post($this->baseUrl().$target);
        } catch (ConnectionException $exception) {
            Log::warning('payment.doku.checkout_unreachable', [
                'invoice_number' => $invoiceNumber,
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ]);

            throw PaymentGatewayException::unreachable();
        }

        $data = $response->json();
        $message = is_array($data) ? ($data['message'][0] ?? null) : null;

        // Sukses DOKU = HTTP 200 + message[0] === "SUCCESS".
        if (! $response->successful() || $message !== 'SUCCESS' || ! is_array($data)) {
            $errors = is_array($data) && is_array($data['error_messages'] ?? null)
                ? $data['error_messages']
                : [];

            Log::warning('payment.doku.checkout_rejected', [
                'invoice_number' => $invoiceNumber,
                'request_id' => $requestId,
                'http_status' => $response->status(),
                'errors' => $errors,
                'response_body' => $response->body(),
            ]);

            throw PaymentGatewayException::rejected($invoiceNumber, $errors);
        }

        return $data;
    }

    /**
     * Rincian item untuk halaman DOKU. DOKU mensyaratkan
     * `sum(price * quantity) === order.amount`; bila diskon membuat total
     * tidak sama, dipakai satu baris total agar tidak ada selisih nominal.
     *
     * @return array<int, array{name: string, price: int, quantity: int}>
     */
    private function buildLineItems(Order $order, int $amount): array
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $price = (int) round((float) $item->price_snapshot);
            $quantity = (int) $item->quantity;

            if ($price < 1 || $quantity < 1) {
                $lineItems = [];
                break;
            }

            $name = $this->sanitizeDokuText(sprintf(
                '%s - %s',
                (string) ($item->product_name_snapshot ?? 'Produk'),
                (string) ($item->variant_name_snapshot ?? 'Default'),
            ));

            $lineItems[] = [
                'name' => Str::limit($name, 255, ''),
                'price' => $price,
                'quantity' => $quantity,
            ];
        }

        $shippingCost = (int) round((float) $order->shipping_cost);

        if ($lineItems !== [] && $shippingCost > 0) {
            $lineItems[] = [
                'name' => 'Ongkos Kirim',
                'price' => $shippingCost,
                'quantity' => 1,
            ];
        }

        $lineTotal = array_sum(array_map(
            static fn (array $line): int => $line['price'] * $line['quantity'],
            $lineItems,
        ));

        if ($lineItems === [] || $lineTotal !== $amount) {
            return [[
                'name' => Str::limit($this->sanitizeDokuText('Total pesanan '.$order->order_number), 255, ''),
                'price' => $amount,
                'quantity' => 1,
            ]];
        }

        return $lineItems;
    }

    /**
     * @return array<string, string>
     */
    private function buildCustomer(Order $order): array
    {
        $user = $order->user;
        $name = (string) ($order->shipping_recipient_name ?: $user?->name ?: '');
        // DOKU membatasi `customer.name` ke karakter alfabet.
        $name = trim((string) preg_replace('/[^\p{L}\s.\'-]/u', ' ', $name));

        $address = $order->shipping_full_address !== null
            ? $this->sanitizeDokuText((string) $order->shipping_full_address)
            : null;
        $city = $this->sanitizeDokuText((string) ($order->shippingAddressData()['city'] ?? ''));

        $customer = [
            'id' => $user?->id !== null ? (string) $user->id : null,
            'name' => $name !== '' ? Str::limit($name, 255, '') : null,
            'email' => $user?->email,
            'phone' => $this->normalizePhone($order->shipping_phone ?: $user?->phone),
            'address' => $address !== '' ? Str::limit((string) $address, 400, '') : null,
            'city' => $city !== '' ? $city : null,
            'country' => 'ID',
        ];

        return array_filter(
            $customer,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * Bersihkan teks agar sesuai whitelist karakter DOKU Checkout:
     * a-z A-Z 0-9 . - / + , = _ : ' @ % ( ) dan spasi.
     * Mengganti karakter dash tipografis (— / –) menjadi hyphen (-) biasa.
     */
    private function sanitizeDokuText(string $text): string
    {
        // Ganti em-dash (—) dan en-dash (–) ke hyphen (-)
        $converted = str_replace(["\u{2014}", "\u{2013}", '—', '–'], '-', $text);

        // Hapus karakter apa pun yang di luar whitelist DOKU
        $cleaned = preg_replace("/[^a-zA-Z0-9.\-\/+,\=_\:'@%() ]/u", ' ', $converted);

        // Normalisasi spasi ganda
        return trim((string) preg_replace('/\s+/', ' ', (string) $cleaned));
    }

    /**
     * @return array<int, string>|null
     */
    private function methodTypes(?string $paymentMethod): ?array
    {
        if (! config('doku.restrict_payment_methods', true)) {
            return null;
        }

        return self::PAYMENT_METHOD_TYPES[(string) $paymentMethod] ?? null;
    }

    private function callbackUrl(Order $order): ?string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        if ($frontendUrl === '') {
            return null;
        }

        return $frontendUrl.'/orders/'.$order->order_number;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            return '62'.ltrim($digits, '0');
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    private function parseExpiredDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('YmdHis', $value, 'Asia/Jakarta');
        } catch (Throwable) {
            return null;
        }

        // `expired_date` DOKU memakai UTC+7 (Asia/Jakarta) dan format yyyyMMddHHmmss.
        return $parsed instanceof CarbonImmutable ? $parsed->utc() : null;
    }

    private function timestamp(): string
    {
        // DOKU mensyaratkan ISO-8601 UTC+0 (akhiran "Z") untuk Request-Timestamp.
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('doku.base_url'), '/');
    }

    private function paymentWindowMinutes(): int
    {
        return max(1, (int) config('doku.payment_window_minutes', 60));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('doku.connect_timeout_seconds', 3));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('doku.timeout_seconds', 10));
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw PaymentGatewayException::notConfigured();
        }
    }
}
