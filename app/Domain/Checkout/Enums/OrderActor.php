<?php

namespace App\Domain\Checkout\Enums;

/**
 * Aktor yang memicu perubahan status order.
 *
 * system_map §4.4.4: "Setiap transisi mencatat aktor (admin, customer,
 * gateway, atau system) dan riwayat."
 *
 * Ini disimpan sebagai string di `order_status_histories.actor_type`. Nilai
 * enum ini WAJIB konsisten dengan audit trail, jadi jangan sembarang menambah
 * nilai baru tanpa Impacts ke dashboard admin.
 */
enum OrderActor: string
{
    /** Admin mengubah status lewat panel Filament. */
    case Admin = 'admin';

    /** Pelanggan, mis. ketika membatalkan pesanan sendiri. */
    case Customer = 'customer';

    /** Payment gateway lewat webhook. */
    case Gateway = 'gateway';

    /** Cron job / queue worker / sistem otomatis. */
    case System = 'system';
}
