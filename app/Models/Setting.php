<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The application-wide settings an Admin can change on the Settings page: one
 * row per setting that differs from its default. Read through get() and the
 * named accessors below, never row by row — the whole lot is cached until one
 * is changed.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * The fewest and most seconds between a page's checks for changes (see
     * public/js/live.js).
     *
     * @var array{0: int, 1: int}
     */
    public const LIVE_INTERVAL_RANGE = [2, 60];

    public const DEFAULT_LIVE_INTERVAL = 3;

    private const CACHE_KEY = 'settings';

    /**
     * A setting's value, or $default if it has never been changed.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::stored()[$key] ?? $default;
    }

    /**
     * Changes a setting; null puts it back to its default.
     */
    public static function put(string $key, ?string $value): void
    {
        if ($value === null) {
            static::query()->where('key', $key)->delete();
        } else {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * What the panel calls itself — in the tab title, the sidebar and on the
     * sign-in page. The Admin's own wording, or the app's configured name.
     */
    public static function companyName(): string
    {
        return static::get('company_name') ?: config('app.name', 'RFQMS');
    }

    /**
     * Seconds between an open page's checks for changes, kept within reason
     * whatever was stored.
     */
    public static function liveIntervalSeconds(): int
    {
        [$fewest, $most] = self::LIVE_INTERVAL_RANGE;

        return max($fewest, min($most, (int) static::get('live_interval', self::DEFAULT_LIVE_INTERVAL)));
    }

    /**
     * @return array<string, string|null>
     */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }
}
