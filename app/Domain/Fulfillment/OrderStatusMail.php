<?php

namespace App\Domain\Fulfillment;

use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email perubahan status pesanan.
 *
 * PRD §3.9: "Notifikasi otomatis ke pelanggan (email/WhatsApp) di setiap
 * perubahan status utama."
 *
 * Bahasa: Bahasa Indonesia. PRD §7 menaruh multi-bahasa di luar scope, jadi
 * tidak ada field `locale` di sini.
 *
 * Catatan desain: Mailable ini menerima MODEL Order, bukan ID. Tapi job yang
 * memanggilnya yang menyerialisasi ID-nya (lihat SendOrderStatusEmailJob) —
 * jadi modelnya selalu di-load ulang dari database dan email selalu
 * mencerminkan kondisi terbaru.
 */
class OrderStatusMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status',
            with: [
                'order' => $this->order,
                'statusLabel' => $this->statusLabel(),
                'items' => $this->order->items,
                'trackingUrl' => $this->trackingUrl(),
            ],
        );
    }

    /**
     * Judul email per status.
     *
     * Nada berbeda per status itu penting: kabar buruk (batal, kedaluwarsa)
     * tidak boleh terdengar seperti konfirmasi. PRD §3.9 tidak merinci
     * copywriting-nya, jadi ini keputusan produk yang masih bisa diubah.
     */
    private function subject(): string
    {
        return match ($this->order->status) {
            OrderStatus::Dibayar => "Pembayaran diterima — pesanan #{$this->order->order_number}",
            OrderStatus::Diproses => "Pesanan #{$this->order->order_number} sedang diproses",
            OrderStatus::Dikirim => "Pesanan #{$this->order->order_number} telah dikirim",
            OrderStatus::Selesai => "Pesanan #{$this->order->order_number} selesai",
            OrderStatus::Dibatalkan => "Pesanan #{$this->order->order_number} dibatalkan",
            OrderStatus::Expired => "Pesanan #{$this->order->order_number} kedaluwarsa",
            default => "Pembaruan pesanan #{$this->order->order_number}",
        };
    }

    private function statusLabel(): string
    {
        return match ($this->order->status) {
            OrderStatus::MenungguPembayaran => 'Menunggu Pembayaran',
            OrderStatus::Dibayar => 'Sudah Dibayar',
            OrderStatus::Diproses => 'Sedang Diproses',
            OrderStatus::Dikirim => 'Dalam Pengiriman',
            OrderStatus::Selesai => 'Selesai',
            OrderStatus::Expired => 'Kedaluwarsa',
            OrderStatus::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Link lacak pesanan.
     *
     * Nomor order dipakai sebagai penanda karena itu yang buyer punya —
     * PRD §3.5: riwayat pesanan menampilkan status terkini.
     */
    private function trackingUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/lacak?order='
            .urlencode($this->order->order_number);
    }
}
