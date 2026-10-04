<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;

/**
 * Halaman browse `admin_activity_logs`.
 *
 * PRD §3.10: "Log aktivitas admin (siapa mengubah apa) untuk akuntabilitas."
 * PRD §6A: "perubahan stok offline oleh admin tercatat dalam audit log."
 *
 * Halaman ini sepenuhnya baca-saja. Tidak ada cara untuk menambah, mengubah,
 * atau menghapus baris dari panel: log ditulis oleh sistem saat operasi
 * bisnis terjadi, bukan oleh operator.
 *
 * Nilai `old_values` dan `new_values` sudah melewati
 * `LogAdminActivity::scrub()`, sehingga kata sandi dan token tidak pernah
 * tersimpan di sini (PRD §3.12).
 */
class AuditLog extends Page
{
    protected static ?string $navigationLabel = 'Log Aktivitas Admin';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Log Aktivitas Admin';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(AuditLogTable::class),
        ]);
    }
}
