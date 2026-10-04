<?php

namespace App\Filament\Resources\Users;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Manajemen role & akun admin.
 *
 * ===================================================================
 *  INI ENDPOINT YANG MEMBUAT AC §3.10 DAPAT DIUJI
 * ===================================================================
 * "Given Staff Operasional login (bukan Superadmin), When mencoba akses menu
 *  manajemen role/user, Then akses ditolak (403 Forbidden)."
 *
 * Menyembunyikan item menu TIDAK cukup. Vendor code Filament sendiri
 * memperingatkan hal itu di `HasNavigation::shouldRegisterNavigation()`:
 * navigation visibility bukan kontrol akses. Karena itu:
 *   - `canAccess()` memanggil ability `manageUsers`, bukan sekadar
 *     `shouldRegisterNavigation()`.
 *   - Halaman create/edit/delete memanggil `UserPolicy` yang berdasar
 *     `manageUsers()`.
 *
 * `Gate::before()` di `UserPolicy` juga menutup jalur lain: akun tanpa role
 * admin ditolak di semua ability, termasuk `viewAny`.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Akun Admin';

    protected static ?string $pluralModelLabel = 'Akun Admin';

    protected static ?string $navigationLabel = 'Role & Pengguna';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'pengguna';

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        // Hanya akun yang punya role admin. Akun pelanggan di-list dari
        // halaman lain (atau tidak perlu sama sekali), dan menampilkannya di
        // sini berisiko membuat admin salah mengedit role pelanggan jadi
        // superadmin.
        return parent::getEloquentQuery()
            ->whereNotNull('role')
            ->whereIn('role', ['superadmin', 'staff']);
    }

    /**
     * Gerbang akses tingkat resource untuk halaman kustom.
     */
    public static function canManageUsers(): bool
    {
        return Gate::check('manageUsers', self::getModel());
    }

    /**
     * Hanya Superadmin yang boleh masuk ke resource ini sama sekali.
     *
     * Dipanggil oleh Filament untuk `canAccess()` (route middleware) dan oleh
     * setiap halaman. Menyembunyikan menu saja tidak menutup endpoint.
     */
    public static function canAccess(): bool
    {
        return self::canManageUsers();
    }

    /**
     * Opsi role. Hanya dua, sesuai PRD §3.10: "minimal role Superadmin &
     * Staff Operasional dengan hak akses berbeda".
     *
     * Nilai `null` TIDAK ditawarkan di sini. Akun tanpa role adalah pelanggan,
     * dan pelanggan tidak boleh diubah lewat panel ini.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            'superadmin' => 'Superadmin',
            'staff' => 'Staf Operasional',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Akun')
                ->schema([
                    TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Dipakai untuk login ke panel admin.'),
                ])
                ->columns(2),

            Section::make('Role & Keamanan')
                ->description('PRD §3.10: Superadmin dan Staf Operasional punya hak akses berbeda. Hanya Superadmin yang boleh mengubah baris ini.')
                ->schema([
                    Select::make('role')
                        ->label('Role')
                        ->options(self::roleOptions())
                        ->required()
                        ->native(false)
                        ->helperText('Staf Operasional bisa mengelola katalog, stok, order, dan voucher, tetapi tidak bisa mengelola role, pengguna, atau pengaturan toko.'),

                    // Password TIDAK pernah ditampilkan ulang, dan TIDAK pernah
                    // masuk `admin_activity_logs` (lihat `LogAdminActivity::scrub`).
                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->minLength(12)
                        // Saat edit, mengosongkan berarti "jangan diubah".
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->confirmed()
                        ->helperText('Panjang minimal 12 karakter. Saat mengubah akun yang sudah ada, kosongkan bila tidak ingin mengganti password.')
                        ->rule('min:12'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'superadmin' ? 'Superadmin' : 'Staf Operasional')
                    ->color(fn (?string $state): string => $state === 'superadmin' ? 'danger' : 'info')
                    ->sortable(),

                // `last_login_at` sengaja tidak ditampilkan: tabel `users`
                // tidak punya kolom itu. Menambahkannya berarti mengubah
                // skema database, dan itu di luar lingkup lapisan admin.
                // Sumber jawaban "kapan terakhir masuk" sementara adalah
                // `admin_activity_logs`.
                TextColumn::make('created_at')
                    ->label('Akun Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(self::roleOptions()),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    // Menjaga agar superadmin tidak mengunci dirinya sendiri
                    // keluar dari panel. Sisa satu superadmin harus selalu ada.
                    ->visible(fn (User $record): bool => ! self::isLastSuperAdmin($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada akun admin')
            ->emptyStateDescription('Akun admin dibuat dari sini. Akun dengan role null adalah pelanggan dan tidak muncul di daftar ini.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Apakah akun ini superadmin terakhir yang tersisa?
     *
     * Tanpa penjaga ini, superadmin bisa menghapus akunnya sendiri dan membuat
     * seluruh panel tidak bisa dikelola siapa pun.
     */
    public static function isLastSuperAdmin(User $record): bool
    {
        if ($record->role !== 'superadmin') {
            return false;
        }

        return static::getEloquentQuery()
            ->where('role', 'superadmin')
            ->whereKeyNot($record->getKey())
            ->doesntExist();
    }

    /**
     * Catat pembuatan akun. Password TIDAK ikut masuk log karena
     * `LogAdminActivity::scrub()` membuang kunci yang mengandung "password".
     */
    public static function logCreation(User $record): void
    {
        app(LogAdminActivity::class)->created($record, $record->getAttributes());
    }
}
