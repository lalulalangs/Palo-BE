<?php

namespace App\Filament\Pages;

use App\Enums\AdminFeature;
use App\Filament\Concerns\HasAdminFeatureAccess;
use App\Models\AppSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageSocialMediaSettings extends Page
{
    use HasAdminFeatureAccess;
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Media Sosial & Kontak';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Pengaturan Media Sosial & Kontak';

    protected static ?string $slug = 'pengaturan-media-sosial';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public static function getAdminFeature(): AdminFeature
    {
        return AdminFeature::SocialMediaSettings;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $settings = AppSetting::getMany([
            'instagram_url' => null,
            'instagram_handle' => null,
            'facebook_url' => null,
            'whatsapp_number' => null,
            'whatsapp_default_message' => null,
        ]);

        $this->form->fill($settings);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Pengaturan Media Sosial & Kontak')
                    ->tabs([
                        Tab::make('Media Sosial')
                            ->icon(Heroicon::OutlinedShare)
                            ->schema([
                                TextInput::make('instagram_url')
                                    ->label('URL Profil Instagram')
                                    ->url()
                                    ->placeholder('https://instagram.com/palorinjani')
                                    ->helperText('Tautan langsung ke profil Instagram resmi Palo Rinjani.')
                                    ->maxLength(255),
                                TextInput::make('instagram_handle')
                                    ->label('Handle Instagram')
                                    ->placeholder('@palorinjani')
                                    ->helperText('Username/handle Instagram untuk teks kartu kontak (contoh: @palorinjani).')
                                    ->maxLength(100),
                                TextInput::make('facebook_url')
                                    ->label('URL Halaman Facebook')
                                    ->url()
                                    ->placeholder('https://facebook.com/palorinjani')
                                    ->helperText('Tautan langsung ke halaman Facebook resmi Palo Rinjani.')
                                    ->maxLength(255),
                                // TODO: Implementasi field input tiktok_url (URL profil TikTok resmi Palo Rinjani)
                            ])
                            ->columns(1),

                        Tab::make('Kontak WhatsApp')
                            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                            ->schema([
                                TextInput::make('whatsapp_number')
                                    ->label('Nomor WhatsApp Customer Service')
                                    ->tel()
                                    ->placeholder('081234567890')
                                    ->regex('/^(\+?62|0)[0-9]{8,14}$/')
                                    ->validationMessages([
                                        'regex' => 'Format nomor WhatsApp tidak valid. Gunakan format Indonesia (contoh: 081234567890 atau 6281234567890).',
                                    ])
                                    ->helperText('Nomor ini digunakan untuk redirect checkout tamu (wa.me) dan tombol WhatsApp CS melayang di storefront.')
                                    ->maxLength(25),
                                Textarea::make('whatsapp_default_message')
                                    ->label('Pesan Sapaan WhatsApp Default (Opsional)')
                                    ->rows(3)
                                    ->placeholder('Halo Admin Palo Mountain Goods, saya ingin bertanya seputar produk...')
                                    ->helperText('Pesan pembuka default saat pengunjung membuka percakapan WhatsApp CS.')
                                    ->maxLength(500),
                                // TODO: Implementasi field input gmaps_url / store_maps_url (tautan Google Maps lokasi gerai Senaru)
                            ])
                            ->columns(1),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    EmbeddedSchema::make('form'),
                ])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Perubahan')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $normalize = fn ($value) => blank($value) ? null : trim((string) $value);

        AppSetting::setMany([
            'instagram_url' => $normalize($state['instagram_url'] ?? null),
            'instagram_handle' => $normalize($state['instagram_handle'] ?? null),
            'facebook_url' => $normalize($state['facebook_url'] ?? null),
            'whatsapp_number' => $normalize($state['whatsapp_number'] ?? null),
            'whatsapp_cs' => $normalize($state['whatsapp_number'] ?? null),
            'whatsapp_default_message' => $normalize($state['whatsapp_default_message'] ?? null),
        ]);

        Notification::make()
            ->title('Pengaturan berhasil disimpan')
            ->success()
            ->send();
    }
}
