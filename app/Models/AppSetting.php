<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    use HasFactory;

    private const CACHE_KEY = 'settings:all';

    private const CACHE_TTL_SECONDS = 3600;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get single setting value by key.
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $settings = static::allValues();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Set or update a single setting value.
     */
    public static function setValue(string $key, ?string $value): static
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget(self::CACHE_KEY);

        return $setting;
    }

    /**
     * Get multiple settings as an associative key => value array with fallback defaults.
     *
     * @param  array<string, string|null>  $defaults
     * @return array<string, string|null>
     */
    public static function getMany(array $defaults = []): array
    {
        $settings = static::allValues();

        $result = [];
        foreach ($defaults as $key => $defaultValue) {
            $result[$key] = array_key_exists($key, $settings) ? $settings[$key] : $defaultValue;
        }

        return $result;
    }

    /**
     * Set multiple settings from an associative array.
     *
     * @param  array<string, string|null>  $values
     */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::setValue($key, $value);
        }
    }

    /**
     * Seluruh pengaturan sebagai map key => value, di-cache agar tidak
     * menembak database pada setiap request.
     *
     * @return array<string, string|null>
     */
    private static function allValues(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => static::query()->pluck('value', 'key')->toArray(),
        );
    }
}
