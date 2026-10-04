{{-- Email perubahan status pesanan. Nada & layout sengaja dibuat
    sederhana supaya mudah diubah nanti. Semua data produk dibaca dari SNAPSHOT order_items,
     bukan dari katalog — lihat OrderStatusResource.

     PRD §3.9: "Notifikasi otomatis ke pelanggan di setiap perubahan status
     utama." --}}
<x-mailer::message>
# Pesanan {{ $order->order_number }}

Halo {{ $order->shipping_address['recipient_name'] ?? 'Pembeli' }},

Status pesanan Anda sekarang: **{{ $statusLabel }}**.

@if ($order->status->value === 'dikirim')
Nomor resi: **{{ $order->tracking_number }}**
@endif

## Rincian Pesanan

<x-mailer::table>
| Produk | Varian | Jumlah | Subtotal |
|:-------|:-------|-------:|---------:|
@foreach ($items as $item)
| {{ $item->product_name }} | {{ $item->displayVariant() ?? '-' }} | {{ $item->quantity }} | Rp {{ number_format($item->subtotal, 0, ',', '.') }} |
@endforeach

<x-mailer::table>

| | |
|:--|--:|
| Subtotal | Rp {{ number_format($order->subtotal, 0, ',', '.') }} |
@if ($order->discount_total > 0)
| Diskon ({{ $order->voucher_code }}) | −Rp {{ number_format($order->discount_total, 0, ',', '.') }} |
@endif
| Ongkos kirim | Rp {{ number_format($order->shipping_cost, 0, ',', '.') }} |
| **Total** | **Rp {{ number_format($order->grand_total, 0, ',', '.') }}** |

<x-mailer::button :url="$trackingUrl">
Lacak Pesanan
</x-mailer::button>

Terima kasih,<br>
{{ config('palorinjani.brand.public_name') }}

{{-- Catatan: status & isi pesanan di email ini sengaja dibaca dari snapshot.
     Kalau produk dihapus dari katalog, email ini tetap menampilkan apa yang
     benar-benar dibeli pembeli (PRD §3.5 AC). --}}
</x-mailer::message>
