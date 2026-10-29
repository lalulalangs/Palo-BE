<?php

namespace App\Services\Payments\Doku;

use App\Exceptions\PaymentGatewayException;

/**
 * Signature DOKU non-SNAP.
 *
 * Komponen (satu per baris, tanpa baris kosong di akhir):
 *   Client-Id
 *   Request-Id
 *   Request-Timestamp
 *   Request-Target
 *   Digest            (hanya untuk method POST)
 *
 * Signature = "HMACSHA256=" . base64(hmac_sha256(komponen, Secret-Key)).
 *
 * Referensi:
 * - https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-component-from-request-header
 * - https://developers.doku.com/get-started-with-doku-api/signature-component/non-snap/signature-from-api-get-method
 */
class DokuSignature
{
    /**
     * Buat signature untuk request keluar (POST) atau verifikasi notifikasi.
     *
     * @param  string|null  $rawBody  Body mentah; `null` untuk method GET (tanpa Digest).
     */
    public function sign(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $requestTarget,
        ?string $rawBody
    ): string {
        $component = $this->component($clientId, $requestId, $timestamp, $requestTarget, $rawBody);

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, $this->secretKey(), true));
    }

    /**
     * Verifikasi signature notifikasi HTTP dari DOKU.
     *
     * `Request-Target` untuk notifikasi adalah path URL notifikasi milik
     * merchant (bukan path API DOKU), lihat dokumentasi signature DOKU.
     *
     * @param  array{client_id?: string, request_id?: string, request_timestamp?: string, signature?: string}  $headers
     */
    public function verify(string $payload, array $headers, string $requestTarget): bool
    {
        $clientId = (string) ($headers['client_id'] ?? '');
        $requestId = (string) ($headers['request_id'] ?? '');
        $timestamp = (string) ($headers['request_timestamp'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        $configuredClientId = (string) config('doku.client_id');

        if ($clientId === '' || $requestId === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Notifikasi untuk client lain tidak boleh diterima.
        if ($configuredClientId === '' || ! hash_equals($configuredClientId, $clientId)) {
            return false;
        }

        return hash_equals(
            $this->sign($clientId, $requestId, $timestamp, $requestTarget, $payload),
            $signature
        );
    }

    private function component(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $requestTarget,
        ?string $rawBody
    ): string {
        $components = [
            'Client-Id:'.$clientId,
            'Request-Id:'.$requestId,
            'Request-Timestamp:'.$timestamp,
            'Request-Target:'.$requestTarget,
        ];

        if ($rawBody !== null) {
            $components[] = 'Digest:'.base64_encode(hash('sha256', $rawBody, true));
        }

        return implode("\n", $components);
    }

    private function secretKey(): string
    {
        $secretKey = trim((string) config('doku.secret_key'));

        if ($secretKey === '') {
            throw PaymentGatewayException::notConfigured();
        }

        return $secretKey;
    }
}
