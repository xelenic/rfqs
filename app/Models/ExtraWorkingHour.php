<?php

namespace App\Models;

use Database\Factories\ExtraWorkingHourFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One range of a person's own working time, on top of everyone's working
 * hours (Setting::workingHours()): on the weekdays in days, from start to end
 * ("H:i", the same day, in the working hours' time zone), counting from the
 * day starts_on — and, once it's been taken away, until ended_at. Their time
 * counts in it as in the working hours — on the Time Spent sheet, their
 * countdowns, Data Entry's Start (Setting::workingPeriodsBetween() with their
 * id). Taking one away ends it rather than deleting it: time is worked out
 * afresh each time it's shown, and what it counted must stay counted. Kept by
 * an Admin on Settings → Extra Hours, for the people whose time is tracked
 * (AttendanceSheet::people()). Read through byUser(), cached until one
 * changes.
 */
class ExtraWorkingHour extends Model
{
    /** @use HasFactory<ExtraWorkingHourFactory> */
    use HasFactory;

    private const CACHE_KEY = 'extra_working_hours';

    protected $fillable = [
        'user_id',
        'days',
        'start',
        'end',
        'starts_on',
        'ended_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'array',
            'starts_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Everyone's extra ranges, ended ones too, by user id — starts_on as
     * "Y-m-d", ended_at as a Unix timestamp or null.
     *
     * @return array<int, array<int, array{days: array<int, string>, start: string, end: string, starts_on: string, ended_at: ?int}>>
     */
    public static function byUser(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->orderBy('start')
            ->get(['user_id', 'days', 'start', 'end', 'starts_on', 'ended_at'])
            ->groupBy('user_id')
            ->map(fn ($ranges) => $ranges->map(fn (self $range) => [
                'days' => $range->days,
                'start' => $range->start,
                'end' => $range->end,
                'starts_on' => $range->starts_on->toDateString(),
                'ended_at' => $range->ended_at?->getTimestamp(),
            ])->values()->all())
            ->all());
    }

    /**
     * $userId's extra ranges — none for most people.
     *
     * @return array<int, array{days: array<int, string>, start: string, end: string, starts_on: string, ended_at: ?int}>
     */
    public static function forUser(?int $userId): array
    {
        return $userId === null ? [] : (static::byUser()[$userId] ?? []);
    }

    /**
     * How the days read: "Every day", "Mon–Fri", "Mon, Wed, Sat".
     */
    public function daysLabel(): string
    {
        return Setting::daysLabel($this->days ?? []);
    }

    /**
     * The ones still counting — not taken away.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Whose time it is.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
