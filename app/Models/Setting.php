<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The application-wide settings an Admin can change on the Settings page: one
 * row per setting that differs from its default — the working week among them,
 * kept as one JSON value (see workingHours()). Read through get() and the
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

    /**
     * The days of the week, in the order the Settings page lists them.
     *
     * @var array<int, string>
     */
    public const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * A day's working hours until an Admin changes them — times as "HH:MM",
     * 24-hour; a null lunch means none.
     *
     * @var array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}
     */
    public const DEFAULT_WORKING_DAY = ['working' => true, 'start' => '08:30', 'end' => '17:00', 'lunch_start' => '12:30', 'lunch_end' => '13:30'];

    /**
     * The days off until an Admin changes them.
     *
     * @var array<int, string>
     */
    private const DEFAULT_DAYS_OFF = ['saturday', 'sunday'];

    /**
     * The time zone the working hours are in until an Admin changes it. The
     * app itself keeps time in UTC; the working day is local.
     */
    public const DEFAULT_TIMEZONE = 'Asia/Colombo';

    /**
     * How long, in working minutes, Sourcing has to complete a part of each
     * priority until an Admin changes it — what the countdown on their
     * Pending list runs from (Rfq::sourcingCountdown()).
     *
     * @var array<string, int>
     */
    public const DEFAULT_SOURCING_TARGETS = ['Low' => 1440, 'Medium' => 960, 'High' => 480, 'Urgent' => 240];

    /**
     * The fewest and most working minutes a Sourcing target can be.
     *
     * @var array{0: int, 1: int}
     */
    public const SOURCING_TARGET_RANGE = [15, 60000];

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
     * The working week, every day in WEEKDAYS order: whether it's worked, its
     * working hours and its lunch. What was saved on the Settings page, with
     * anything never saved — or a day that never was — at its default. A day
     * off keeps the times it last had, so turning it back on brings them back.
     *
     * @return array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
     */
    public static function workingHours(): array
    {
        $saved = json_decode((string) static::get('working_hours'), true);
        $saved = is_array($saved) ? $saved : [];

        return collect(self::WEEKDAYS)->mapWithKeys(fn (string $day) => [$day => array_merge(
            self::DEFAULT_WORKING_DAY,
            ['working' => ! in_array($day, self::DEFAULT_DAYS_OFF, true)],
            array_intersect_key(is_array($saved[$day] ?? null) ? $saved[$day] : [], self::DEFAULT_WORKING_DAY),
        )])->all();
    }

    /**
     * Saves the working week — see workingHours() for its shape.
     *
     * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
     */
    public static function putWorkingHours(array $week): void
    {
        static::put('working_hours', json_encode($week));
    }

    /**
     * The minutes worked on one day of workingHours(): its working time less
     * whatever part of it lunch takes. None on a day off.
     *
     * @param  array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}  $day
     */
    public static function workingMinutes(array $day): int
    {
        if (! $day['working']) {
            return 0;
        }

        $start = self::minutesOfDay($day['start']);
        $end = self::minutesOfDay($day['end']);
        $worked = max(0, $end - $start);

        if ($day['lunch_start'] !== null && $day['lunch_end'] !== null) {
            $worked -= max(0, min($end, self::minutesOfDay($day['lunch_end'])) - max($start, self::minutesOfDay($day['lunch_start'])));
        }

        return $worked;
    }

    /**
     * The time zone the working hours are in — what was saved alongside them,
     * or DEFAULT_TIMEZONE, and never one PHP doesn't know.
     */
    public static function timezone(): string
    {
        $saved = static::get('timezone');

        return in_array($saved, timezone_identifiers_list(), true) ? $saved : self::DEFAULT_TIMEZONE;
    }

    /**
     * The working minutes between $from and $to: only the time inside some
     * day's working hours, less lunch, in the working hours' own time zone —
     * nights, lunches and days off don't count. Seconds are added up first,
     * so many short stretches don't lose a minute each.
     */
    public static function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return intdiv(static::workingSecondsBetween($from, $to), 60);
    }

    /**
     * workingMinutesBetween(), to the second.
     */
    public static function workingSecondsBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $seconds = 0;

        foreach (static::workingPeriodsBetween($from, $to) as [$periodStart, $periodEnd]) {
            $seconds += $periodEnd - $periodStart;
        }

        return $seconds;
    }

    /**
     * The working periods between $from and $to — each day's working hours
     * either side of lunch, in the working hours' own time zone, clipped to
     * $from and $to — as Unix timestamps, earliest first. What the countdown
     * on Sourcing's Pending list ticks against in the browser.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function workingPeriodsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $zone = static::timezone();
        $from = CarbonImmutable::instance($from)->setTimezone($zone);
        $to = CarbonImmutable::instance($to)->setTimezone($zone);

        if ($to <= $from) {
            return [];
        }

        $week = static::workingHours();
        $periods = [];

        for ($day = $from->startOfDay(); $day < $to; $day = $day->addDay()) {
            $hours = $week[strtolower($day->englishDayOfWeek)];

            if (! $hours['working']) {
                continue;
            }

            $dayPeriods = $hours['lunch_start'] !== null && $hours['lunch_end'] !== null
                ? [[$hours['start'], $hours['lunch_start']], [$hours['lunch_end'], $hours['end']]]
                : [[$hours['start'], $hours['end']]];

            foreach ($dayPeriods as [$periodStart, $periodEnd]) {
                $start = max($from, $day->setTimeFromTimeString($periodStart));
                $end = min($to, $day->setTimeFromTimeString($periodEnd));

                if ($end > $start) {
                    $periods[] = [$start->getTimestamp(), $end->getTimestamp()];
                }
            }
        }

        return $periods;
    }

    /**
     * workingSecondsBetween(), day by day: the working seconds that fall on
     * each date ("Y-m-d", in the working hours' own time zone) from $from to
     * $to. Days with none aren't listed.
     *
     * @return array<string, int>
     */
    public static function workingSecondsByDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $zone = static::timezone();
        $days = [];

        foreach (static::workingPeriodsBetween($from, $to) as [$periodStart, $periodEnd]) {
            $date = CarbonImmutable::createFromTimestamp($periodStart, $zone)->toDateString();
            $days[$date] = ($days[$date] ?? 0) + $periodEnd - $periodStart;
        }

        return $days;
    }

    /**
     * The first day ("Y-m-d") a person's time only counts once that day's
     * attendance sheet (AttendanceSheet) has them present — every day before
     * it counts as it is. Null: attendance is off, all time counts.
     */
    public static function attendanceSince(): ?string
    {
        $saved = static::get('attendance_since');

        return is_string($saved) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $saved) ? $saved : null;
    }

    /**
     * Today's date ("Y-m-d") in the working hours' own time zone.
     */
    public static function today(): string
    {
        return CarbonImmutable::now(static::timezone())->toDateString();
    }

    /**
     * Whether $at falls inside the working hours — when a countdown is
     * ticking rather than paused.
     */
    public static function isWorkingTime(CarbonInterface $at): bool
    {
        return static::workingSecondsBetween($at, CarbonImmutable::instance($at)->addSecond()) > 0;
    }

    /**
     * Sourcing's target per priority, in working minutes — what was saved on
     * the Settings page, or DEFAULT_SOURCING_TARGETS, kept within
     * SOURCING_TARGET_RANGE.
     *
     * @return array<string, int>
     */
    public static function sourcingTargets(): array
    {
        $saved = json_decode((string) static::get('sourcing_targets'), true);
        $saved = is_array($saved) ? $saved : [];
        [$fewest, $most] = self::SOURCING_TARGET_RANGE;

        return collect(self::DEFAULT_SOURCING_TARGETS)
            ->map(fn (int $default, string $priority) => is_numeric($saved[$priority] ?? null)
                ? max($fewest, min($most, (int) $saved[$priority]))
                : $default)
            ->all();
    }

    /**
     * How a countdown with $remainingSeconds to go reads — "3h 20m left",
     * "Under 1m left", "Due now", "Overdue by 45m". Mirrored by admin.js.
     */
    public static function countdownLabel(int $remainingSeconds): string
    {
        return match (true) {
            $remainingSeconds >= 60 => static::hoursLabel(intdiv($remainingSeconds, 60)).' left',
            $remainingSeconds > 0 => 'Under 1m left',
            $remainingSeconds > -60 => 'Due now',
            default => 'Overdue by '.static::hoursLabel(intdiv(-$remainingSeconds, 60)),
        };
    }

    /**
     * The badge a countdown wears: overdue once it's run out, due soon in
     * its last quarter. Mirrored by admin.js.
     */
    public static function countdownBadgeClass(int $remainingSeconds, int $targetSeconds): string
    {
        return match (true) {
            $remainingSeconds <= 0 => 'badge-soft-danger',
            $remainingSeconds <= $targetSeconds / 4 => 'badge-soft-warning',
            default => 'badge-soft-success',
        };
    }

    /**
     * How long $minutes reads — "8h", "7h 30m", "45m". Mirrored by admin.js.
     */
    public static function hoursLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest.'m';
        }

        return $hours.'h'.($rest > 0 ? ' '.$rest.'m' : '');
    }

    /**
     * How long a working time of $seconds reads: hoursLabel() from a minute
     * up, and the seconds themselves below it — "24s", "12m", "7h 30m" — so a
     * short stretch doesn't read as nothing.
     */
    public static function durationLabel(int $seconds): string
    {
        return $seconds > 0 && $seconds < 60 ? $seconds.'s' : static::hoursLabel(intdiv($seconds, 60));
    }

    /**
     * How long an elapsed (clock) time of $seconds reads — "24s", "16m",
     * "3h 5m", "2d 17h": days once it runs past one, since it counts nights
     * and days off too.
     */
    public static function elapsedLabel(int $seconds): string
    {
        if ($seconds < 86400) {
            return static::durationLabel($seconds);
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $days.'d'.($hours > 0 ? ' '.$hours.'h' : '');
    }

    /**
     * "HH:MM" as minutes since midnight.
     */
    private static function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * @return array<string, string|null>
     */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }
}
