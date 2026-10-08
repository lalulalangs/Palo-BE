<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get single setting value by key.
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting !== null ? $setting->value : $default;
    }

    /**
     * Set or update a single setting value.
     */
    public static function setValue(string $key, ?string $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get multiple settings as an associative key => value array with fallback defaults.
     *
     * @param  array<string, string|null>  $defaults
     * @return array<string, string|null>
     */
    public static function getMany(array $defaults = []): array
    {
        $keys = array_keys($defaults);
        $settings = static::query()
            ->when(! empty($keys), fn ($query) => $query->whereIn('key', $keys))
            ->pluck('value', 'key')
            ->toArray();

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
}
