<?php

namespace App\Filament\Pages;

use App\Domain\Checkout\Models\Order;
use App\Domain\Shared\Models\AdminActivityLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Tabel `admin_activity_logs`.
 *
 * Dipisah dari halaman `AuditLog` supaya halaman itu tetap berupa Page
 * (sesuai penempatan di `app/Filament/Pages`) sementara tabelnya tetap memakai
 * komponen tabel Filament yang sudah menangani sorting, filtering, dan paging.
 *
 * `$isDiscovered = false` supaya tabel ini tidak muncul sebagai widget di
 * dashboard. Ia hanya hidup di dalam halaman `AuditLog`.
 */
class AuditLogTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        // Sama seperti resource lain: hanya admin. Akun pelanggan sudah
        // tertolak lebih dulu di `User::canAccessPanel()`.
        return Gate::check('viewAny', Order::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AdminActivityLog::query()
                    ->with('user')
                    ->latest(),
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Pelaku')
                    ->placeholder('Sistem')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Aksi')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created', 'published' => 'success',
                        'updated' => 'primary',
                        'deleted', 'cancelled' => 'danger',
                        'stock_adjusted', 'stock_opname' => 'warning',
                        'shipped' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label('Objek')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject_id')
                    ->label('ID')
                    ->placeholder('-')
                    ->copyable(),

                TextColumn::make('new_values')
                    ->label('Perubahan')
                    ->formatStateUsing(fn (mixed $state): string => self::formatValues($state))
                    ->wrap()
                    ->limit(120)
                    ->placeholder('-')
                    ->tooltip(fn (AdminActivityLog $record): string => self::formatValues($record->new_values))
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->label('Aksi')
                    ->options([
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                        'published' => 'Diterbitkan',
                        'unpublished' => 'Diturunkan',
                        'shipped' => 'Dikirim',
                        'cancelled' => 'Dibatalkan',
                        'status_changed' => 'Status Berubah',
                        'stock_adjusted' => 'Stok Disuaikan',
                        'stock_opname' => 'Opname',
                        'activated' => 'Diaktifkan',
                        'deactivated' => 'Dinonaktifkan',
                        'reconciliation_reviewed' => 'Rekonsiliasi Ditinjau',
                    ]),

                SelectFilter::make('subject_type')
                    ->label('Objek')
                    ->options([
                        'Product' => 'Produk',
                        'Sku' => 'SKU',
                        'Category' => 'Kategori',
                        'Order' => 'Order',
                        'Voucher' => 'Voucher',
                        'AppSetting' => 'Pengaturan',
                        'User' => 'Pengguna',
                        'System' => 'Sistem',
                    ]),

                SelectFilter::make('user')
                    ->label('Pelaku')
                    ->relationship('user', 'name')
                    ->searchable(),

                SelectFilter::make('periode')
                    ->label('Periode')
                    ->options([
                        'today' => 'Hari Ini',
                        'week' => '7 Hari Terakhir',
                        'month' => '30 Hari Terakhir',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'today' => $query->whereDate('created_at', now()->toDateString()),
                            'week' => $query->where('created_at', '>=', now()->subDays(7)),
                            'month' => $query->where('created_at', '>=', now()->subDays(30)),
                            default => $query,
                        };
                    }),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Belum ada aktivitas tercatat')
            ->emptyStateDescription('Setiap perubahan produk, SKU, order, voucher, dan pengaturan akan tercatat di sini.');
    }

    /**
     * Ubah array perubahan menjadi satu baris yang bisa dibaca.
     *
     * Bentuk diff yang dipakai `LogAdminActivity::diff()` adalah
     * `['field' => ['from' => x, 'to' => y]]`, dan itu yang ditampilkan
     * sebagai "x -> y".
     */
    public static function formatValues(mixed $values): string
    {
        if (! is_array($values) || $values === []) {
            return '-';
        }

        $parts = [];

        foreach ($values as $key => $value) {
            if (is_array($value) && array_key_exists('from', $value) && array_key_exists('to', $value)) {
                $parts[] = $key.': '.self::scalarToString($value['from']).' -> '.self::scalarToString($value['to']);

                continue;
            }

            $parts[] = $key.': '.self::scalarToString($value);
        }

        return implode('; ', $parts);
    }

    private static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '(kosong)';
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
