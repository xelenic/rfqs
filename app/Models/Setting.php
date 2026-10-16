<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

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
     * The time a half day off takes until an Admin changes it — the morning
     * off, or the afternoon off (Attendance::HALF_DAY) — times as "HH:MM",
     * 24-hour. See halfDayOff().
     *
     * @var array{morning: array{start: string, end: string}, afternoon: array{start: string, end: string}}
     */
    public const DEFAULT_HALF_DAY_OFF = [
        'morning' => ['start' => '08:30', 'end' => '12:30'],
        'afternoon' => ['start' => '13:30', 'end' => '17:00'],
    ];

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
     * How long, in working minutes, Data Entry has to send a part of each
     * priority to finalize, from their Start, until an Admin changes it —
     * what the countdown on their list runs from (Rfq::dataEntryCountdown()).
     *
     * @var array<string, int>
     */
    public const DEFAULT_DATA_ENTRY_TARGETS = ['Low' => 240, 'Medium' => 120, 'High' => 60, 'Urgent' => 30];

    /**
     * How long the part's Sourcing member has to finalize it once Data Entry
     * sends it to them, by its RFQ's priority, in working minutes, until an
     * Admin changes it — what the countdown on their list runs from then
     * (Rfq::finalizeCountdown()).
     *
     * @var array<string, int>
     */
    public const DEFAULT_FINALIZE_TARGETS = ['Low' => 120, 'Medium' => 60, 'High' => 30, 'Urgent' => 15];

    /**
     * Whether Senior Operations is alerted when someone in Data Entry hasn't
     * started anything for a while in working time with parts waiting on
     * them, and after how many working minutes — until an Admin changes it.
     * See App\Console\Commands\AlertIdleDataEntry.
     *
     * @var array{enabled: bool, minutes: int}
     */
    public const DEFAULT_DATA_ENTRY_IDLE_ALERT = ['enabled' => true, 'minutes' => 10];

    /**
     * The automatic backups (Admin → Backups, backup:scheduled) until an Admin
     * changes them: one a day at 02:00 and one a week on Sunday at 03:00, in
     * the working hours' time zone, uploaded files included.
     *
     * @var array{daily: array{enabled: bool, time: string}, weekly: array{enabled: bool, day: string, time: string}, include_files: bool}
     */
    public const DEFAULT_BACKUP_SCHEDULE = [
        'daily' => ['enabled' => true, 'time' => '02:00'],
        'weekly' => ['enabled' => true, 'day' => 'sunday', 'time' => '03:00'],
        'include_files' => true,
    ];

    /**
     * The lowest and highest an Admin can have RFQ numbers start from (as
     * the number after the prefix: 1670 for RFQ1670). See rfqNumberStart().
     *
     * @var array{0: int, 1: int}
     */
    public const RFQ_NUMBER_START_RANGE = [1, 99999999];

    /**
     * Whether Data Entry's target is worked out per item — so many working
     * seconds an item, times the part's items (Rfq::dataEntryItemsFor()) —
     * rather than by priority (DEFAULT_DATA_ENTRY_TARGETS), until an Admin
     * changes it. See dataEntryPerItem().
     *
     * @var array{enabled: bool, seconds: int}
     */
    public const DEFAULT_DATA_ENTRY_PER_ITEM = ['enabled' => false, 'seconds' => 120];

    /**
     * The fewest and most working seconds an item can be given (30 seconds to
     * four hours).
     *
     * @var array{0: int, 1: int}
     */
    public const DATA_ENTRY_PER_ITEM_RANGE = [30, 14400];

    /**
     * The fewest and most working minutes Data Entry can be idle before the
     * alert goes out.
     *
     * @var array{0: int, 1: int}
     */
    public const DATA_ENTRY_IDLE_MINUTES_RANGE = [1, 480];

    /**
     * The fewest and most working minutes a target — Sourcing's or Data
     * Entry's — can be.
     *
     * @var array{0: int, 1: int}
     */
    public const TARGET_RANGE = [15, 60000];

    /**
     * The format a WC number has to have while the WC check is on
     * (wcFormatRequired()): "WC" and four digits, like WC0123.
     */
    public const WC_NUMBER_PATTERN = '/^WC\d{4}$/';

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
        return static::weekFrom(json_decode((string) static::get('working_hours'), true));
    }

    /**
     * A working week as kept — the company's, a role's or someone's own
     * (WorkingSchedule) — made whole: every day in WEEKDAYS order, anything
     * missing at its default.
     *
     * @return array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
     */
    public static function weekFrom(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];

        return collect(self::WEEKDAYS)->mapWithKeys(fn (string $day) => [$day => array_merge(
            self::DEFAULT_WORKING_DAY,
            ['working' => ! in_array($day, self::DEFAULT_DAYS_OFF, true)],
            array_intersect_key(is_array($saved[$day] ?? null) ? $saved[$day] : [], self::DEFAULT_WORKING_DAY),
        )])->all();
    }

    /**
     * The working week that counts for $userId on $day: their own, else
     * their role's (WorkingSchedule::countingOn()), else the company's — the
     * company's without $userId. $day is a "Y-m-d" in the working hours' time
     * zone, or a moment, taken in it.
     *
     * @return array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
     */
    public static function weekOn(CarbonInterface|string $day, ?int $userId = null): array
    {
        return static::weeksOf($userId)($day instanceof CarbonInterface ? $day->copy()->setTimezone(static::timezone())->toDateString() : $day);
    }

    /**
     * weekOn() for $userId, day after day: a function from a "Y-m-d" to the
     * week that counts on it — the company's every day when nobody has their
     * own hours, so most lookups cost nothing.
     *
     * @return Closure(string): array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
     */
    private static function weeksOf(?int $userId): Closure
    {
        $company = static::workingHours();

        if ($userId === null || ! WorkingSchedule::anyExist()) {
            return fn (string $date) => $company;
        }

        return fn (string $date) => WorkingSchedule::countingOn($userId, $date)['week'] ?? $company;
    }

    /**
     * Where $userId's hours on $day come from (see weekOn()): 'person' —
     * their own — 'role', with the role's name, or 'company'.
     *
     * @return array{source: 'person'|'role'|'company', role: ?string}
     */
    public static function weekSourceOn(CarbonInterface|string $day, int $userId): array
    {
        $date = $day instanceof CarbonInterface ? $day->copy()->setTimezone(static::timezone())->toDateString() : $day;
        $own = WorkingSchedule::countingOn($userId, $date);

        return match ($own['source'] ?? 'company') {
            'person' => ['source' => 'person', 'role' => null],
            'role' => ['source' => 'role', 'role' => Role::query()->whereKey($own['role_id'])->value('name')],
            default => ['source' => 'company', 'role' => null],
        };
    }

    /**
     * How a week reads, in short: its working days grouped by their hours,
     * Monday first — "Mon–Fri 08:30–17:00", "Mon–Thu 08:30–17:00, Fri
     * 08:30–13:00" — or "No working days".
     *
     * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
     */
    public static function weekLabel(array $week): string
    {
        $mondayFirst = [...array_slice(self::WEEKDAYS, 1), self::WEEKDAYS[0]];

        $groups = collect($mondayFirst)
            ->filter(fn (string $day) => $week[$day]['working'] ?? false)
            ->groupBy(fn (string $day) => $week[$day]['start'].'–'.$week[$day]['end'])
            ->map(fn ($days, string $hours) => static::daysLabel($days->all()).' '.$hours);

        return $groups->isEmpty() ? 'No working days' : $groups->join(', ');
    }

    /**
     * How a set of weekdays reads, in WEEKDAYS order: "Every day", "Weekends",
     * three or more days in a row as one stretch — "Mon–Fri", "Mon–Thu" —
     * and the rest one by one: "Mon, Wed, Sat".
     *
     * @param  array<int, string>  $days
     */
    public static function daysLabel(array $days): string
    {
        $order = array_values(array_intersect(self::WEEKDAYS, $days));
        $short = fn (string $day) => ucfirst(substr($day, 0, 3));

        if (count($order) === 7) {
            return 'Every day';
        }

        if ($order === ['sunday', 'saturday']) {
            return 'Weekends';
        }

        $runs = [];

        foreach ($order as $day) {
            $last = count($runs) - 1;
            $follows = $last >= 0 && array_search($day, self::WEEKDAYS, true) === array_search(end($runs[$last]), self::WEEKDAYS, true) + 1;

            if ($follows) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }

        return collect($runs)
            ->flatMap(fn (array $run) => count($run) >= 3 ? [$short($run[0]).'–'.$short(end($run))] : array_map($short, $run))
            ->join(', ');
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
     * so many short stretches don't lose a minute each. With $userId, in the
     * week that counts for them (weekOn()) — their own, or their role's — and
     * their own extra hours count too (ExtraWorkingHour).
     */
    public static function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): int
    {
        return intdiv(static::workingSecondsBetween($from, $to, $userId), 60);
    }

    /**
     * workingMinutesBetween(), to the second.
     */
    public static function workingSecondsBetween(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): int
    {
        $seconds = 0;

        foreach (static::workingPeriodsBetween($from, $to, $userId) as [$periodStart, $periodEnd]) {
            $seconds += $periodEnd - $periodStart;
        }

        return $seconds;
    }

    /**
     * The working periods between $from and $to — each day's working hours
     * either side of lunch, in the working hours' own time zone, clipped to
     * $from and $to — as Unix timestamps, earliest first. With $userId, each
     * day in the week that counts for them that day (weekOn()) — their own,
     * or their role's — and their own extra hours too (ExtraWorkingHour),
     * merged in where they meet or overlap. What the countdowns tick against
     * in the browser.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function workingPeriodsBetween(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $zone = static::timezone();
        $from = CarbonImmutable::instance($from)->setTimezone($zone);
        $to = CarbonImmutable::instance($to)->setTimezone($zone);

        if ($to <= $from) {
            return [];
        }

        $weekOn = static::weeksOf($userId);
        $periods = [];

        for ($day = $from->startOfDay(); $day < $to; $day = $day->addDay()) {
            $hours = $weekOn($day->toDateString())[strtolower($day->englishDayOfWeek)];

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

        $extras = static::extraPeriodsBetween($from, $to, $userId);

        return $extras === [] ? $periods : static::mergedPeriods([...$periods, ...$extras]);
    }

    /**
     * $userId's own extra hours (ExtraWorkingHour) between $from and $to, day
     * by day — each from the day it counts from, until it was taken away —
     * clipped to them, as Unix timestamps. None for most people.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function extraPeriodsBetween(CarbonInterface $from, CarbonInterface $to, ?int $userId): array
    {
        $ranges = ExtraWorkingHour::forUser($userId);

        if ($ranges === []) {
            return [];
        }

        $zone = static::timezone();
        $from = CarbonImmutable::instance($from)->setTimezone($zone);
        $to = CarbonImmutable::instance($to)->setTimezone($zone);
        $periods = [];

        for ($day = $from->startOfDay(); $day < $to; $day = $day->addDay()) {
            $weekday = strtolower($day->englishDayOfWeek);
            $date = $day->toDateString();

            foreach ($ranges as $range) {
                // Only from the day it counts from, and until it was taken away.
                if (! in_array($weekday, $range['days'], true) || $date < $range['starts_on']) {
                    continue;
                }

                $start = max($from, $day->setTimeFromTimeString($range['start']))->getTimestamp();
                $end = min($to, $day->setTimeFromTimeString($range['end']))->getTimestamp();
                $end = $range['ended_at'] !== null ? min($end, $range['ended_at']) : $end;

                if ($end > $start) {
                    $periods[] = [$start, $end];
                }
            }
        }

        return $periods;
    }

    /**
     * $periods in order, those that meet or overlap made one.
     *
     * @param  array<int, array{0: int, 1: int}>  $periods
     * @return array<int, array{0: int, 1: int}>
     */
    private static function mergedPeriods(array $periods): array
    {
        usort($periods, fn (array $one, array $other) => $one[0] <=> $other[0]);
        $merged = [];

        foreach ($periods as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * workingSecondsBetween(), day by day: the working seconds that fall on
     * each date ("Y-m-d", in the working hours' own time zone) from $from to
     * $to. Days with none aren't listed.
     *
     * @return array<string, int>
     */
    public static function workingSecondsByDay(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $zone = static::timezone();
        $days = [];

        foreach (static::workingPeriodsBetween($from, $to, $userId) as [$periodStart, $periodEnd]) {
            $date = CarbonImmutable::createFromTimestamp($periodStart, $zone)->toDateString();
            $days[$date] = ($days[$date] ?? 0) + $periodEnd - $periodStart;
        }

        return $days;
    }

    /**
     * The time a half day off takes — the morning off, and the afternoon off
     * (Attendance::HALF_DAY) — as saved on the Settings page, or
     * DEFAULT_HALF_DAY_OFF.
     *
     * @return array{morning: array{start: string, end: string}, afternoon: array{start: string, end: string}}
     */
    public static function halfDayOff(): array
    {
        $saved = json_decode((string) static::get('half_day_off'), true);
        $saved = is_array($saved) ? $saved : [];

        return collect(self::DEFAULT_HALF_DAY_OFF)
            ->map(fn (array $default, string $half) => array_merge($default, array_intersect_key(is_array($saved[$half] ?? null) ? $saved[$half] : [], $default)))
            ->all();
    }

    /**
     * Saves the time a half day off takes — see halfDayOff() for its shape.
     *
     * @param  array{morning: array{start: string, end: string}, afternoon: array{start: string, end: string}}  $halves
     */
    public static function putHalfDayOff(array $halves): void
    {
        static::put('half_day_off', json_encode($halves));
    }

    /**
     * Whether $at falls in the half of a half day someone's off — 'morning'
     * or 'afternoon' (halfDayOff()) — on its own day, in the working hours'
     * time zone.
     */
    public static function isInHalfDayOff(string $half, CarbonInterface $at): bool
    {
        $off = static::halfDayOff()[$half] ?? null;
        $local = CarbonImmutable::instance($at)->setTimezone(static::timezone());

        return $off !== null
            && $local >= $local->setTimeFromTimeString($off['start'])
            && $local < $local->setTimeFromTimeString($off['end']);
    }

    /**
     * workingSecondsByDay() — all of each day's — with how much of it falls in
     * the morning off and in the afternoon off (halfDayOff()): what a half
     * day off doesn't count, the rest of the day counting as usual. With
     * $userId, their own extra hours count too.
     *
     * @return array<string, array{all: int, morning: int, afternoon: int}>
     */
    public static function workingSecondsWithHalfDaysOff(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $zone = static::timezone();
        $halves = static::halfDayOff();
        $days = [];

        foreach (static::workingPeriodsBetween($from, $to, $userId) as [$periodStart, $periodEnd]) {
            $day = CarbonImmutable::createFromTimestamp($periodStart, $zone);
            $date = $day->toDateString();

            $days[$date] ??= ['all' => 0, 'morning' => 0, 'afternoon' => 0];
            $days[$date]['all'] += $periodEnd - $periodStart;

            foreach ($halves as $half => $off) {
                $offStart = $day->setTimeFromTimeString($off['start'])->getTimestamp();
                $offEnd = $day->setTimeFromTimeString($off['end'])->getTimestamp();

                $days[$date][$half] += max(0, min($periodEnd, $offEnd) - max($periodStart, $offStart));
            }
        }

        return $days;
    }

    /**
     * The time that isn't working time over the $days days from $from's own
     * day — in the working hours' time zone — window by window, each with why:
     * before the day's hours start, lunch, after they end, a day off. As
     * [from, to, why] — Unix timestamps — in order. What Data Entry's Start
     * says out of hours — it still starts, but their time only counts from
     * when working hours come back — and when that is (see offHoursReason()). With
     * $userId, in the week that counts for them (weekOn()), less their own
     * extra hours: a window they cut short says when those start.
     *
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    public static function offHoursWindows(CarbonInterface $from, int $days = 7, ?int $userId = null): array
    {
        $weekOn = static::weeksOf($userId);
        $first = CarbonImmutable::instance($from)->setTimezone(static::timezone())->startOfDay();
        $windows = [];

        for ($ahead = 0; $ahead < $days; $ahead++) {
            $day = $first->addDays($ahead);
            $next = $day->addDay();
            $hours = $weekOn($day->toDateString())[strtolower($day->englishDayOfWeek)];

            if (! $hours['working']) {
                $windows[] = [$day->getTimestamp(), $next->getTimestamp(), "{$day->englishDayOfWeek} is a day off. ".static::backOn($day, $weekOn)];

                continue;
            }

            $windows[] = [$day->getTimestamp(), $day->setTimeFromTimeString($hours['start'])->getTimestamp(), "Working hours haven't started yet — they start at {$hours['start']}."];

            if ($hours['lunch_start'] !== null && $hours['lunch_end'] !== null) {
                $windows[] = [
                    $day->setTimeFromTimeString($hours['lunch_start'])->getTimestamp(),
                    $day->setTimeFromTimeString($hours['lunch_end'])->getTimestamp(),
                    "It's lunch time ({$hours['lunch_start']}–{$hours['lunch_end']}). Time counts again at {$hours['lunch_end']}.",
                ];
            }

            $windows[] = [$day->setTimeFromTimeString($hours['end'])->getTimestamp(), $next->getTimestamp(), "Working hours are over for today — they ended at {$hours['end']}. ".static::backOn($day, $weekOn)];
        }

        $extras = static::extraPeriodsBetween($first, $first->addDays($days), $userId);

        return $extras === [] ? $windows : static::windowsWithout($windows, static::mergedPeriods($extras));
    }

    /**
     * $windows (offHoursWindows()) less $extras — someone's own extra hours —
     * split around them. A piece cut short by extra hours says when they
     * start; the rest say what they did.
     *
     * @param  array<int, array{0: int, 1: int, 2: string}>  $windows
     * @param  array<int, array{0: int, 1: int}>  $extras  merged, in order
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private static function windowsWithout(array $windows, array $extras): array
    {
        $zone = static::timezone();
        $pieces = [];

        foreach ($windows as [$from, $to, $why]) {
            $cursor = $from;

            foreach ($extras as [$extraStart, $extraEnd]) {
                if ($extraEnd <= $cursor || $extraStart >= $to) {
                    continue;
                }

                if ($extraStart > $cursor) {
                    $pieces[] = [$cursor, $extraStart, 'Outside working hours — your extra hours start at '.CarbonImmutable::createFromTimestamp($extraStart, $zone)->format('H:i').'.'];
                }

                $cursor = max($cursor, $extraEnd);
            }

            if ($cursor < $to) {
                $pieces[] = [$cursor, $to, $why];
            }
        }

        return $pieces;
    }

    /**
     * Why $at isn't working time — for $userId, if given — see
     * offHoursWindows(); null when it is.
     */
    public static function offHoursReason(CarbonInterface $at, ?int $userId = null): ?string
    {
        $timestamp = $at->getTimestamp();

        foreach (static::offHoursWindows($at, 1, $userId) as [$from, $to, $why]) {
            if ($timestamp >= $from && $timestamp < $to) {
                return $why;
            }
        }

        return null;
    }

    /**
     * When working time — and so a started part's time — next counts after
     * $day: the next working day's start,
     * in the week that counts that day ($weekOn, see weeksOf()).
     *
     * @param  Closure(string): array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $weekOn
     */
    private static function backOn(CarbonImmutable $day, Closure $weekOn): string
    {
        for ($ahead = 1; $ahead <= 7; $ahead++) {
            $next = $day->addDays($ahead);
            $hours = $weekOn($next->toDateString())[strtolower($next->englishDayOfWeek)];

            if ($hours['working']) {
                return 'Time counts again '.($ahead === 1 ? 'tomorrow' : 'on '.$next->englishDayOfWeek)." at {$hours['start']}.";
            }
        }

        return 'Time counts again once there are working days in Settings.';
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
     * Whether $at falls inside the working hours — or, for $userId, theirs,
     * their own extra hours included — when a countdown is ticking rather
     * than paused.
     */
    public static function isWorkingTime(CarbonInterface $at, ?int $userId = null): bool
    {
        return static::workingSecondsBetween($at, CarbonImmutable::instance($at)->addSecond(), $userId) > 0;
    }

    /**
     * Sourcing's target per priority, in working minutes — what was saved on
     * the Settings page, or DEFAULT_SOURCING_TARGETS, kept within
     * TARGET_RANGE.
     *
     * @return array<string, int>
     */
    public static function sourcingTargets(): array
    {
        return static::targets('sourcing_targets', self::DEFAULT_SOURCING_TARGETS);
    }

    /**
     * The Data Entry idle alert — whether it's on, and after how many working
     * minutes — as saved on the Settings page, or
     * DEFAULT_DATA_ENTRY_IDLE_ALERT, its minutes kept within
     * DATA_ENTRY_IDLE_MINUTES_RANGE.
     *
     * @return array{enabled: bool, minutes: int}
     */
    public static function dataEntryIdleAlert(): array
    {
        $saved = json_decode((string) static::get('data_entry_idle_alert'), true);
        $saved = is_array($saved) ? $saved : [];
        [$fewest, $most] = self::DATA_ENTRY_IDLE_MINUTES_RANGE;

        return [
            'enabled' => is_bool($saved['enabled'] ?? null) ? $saved['enabled'] : self::DEFAULT_DATA_ENTRY_IDLE_ALERT['enabled'],
            'minutes' => is_numeric($saved['minutes'] ?? null)
                ? max($fewest, min($most, (int) $saved['minutes']))
                : self::DEFAULT_DATA_ENTRY_IDLE_ALERT['minutes'],
        ];
    }

    /**
     * Whether a new RFQ's WC number — and a changed one — has to be in the
     * WC0000 format (WC_NUMBER_PATTERN). Off until an Admin turns it on, on
     * Settings → WC Validation. See RfqController::wcNumberChecked().
     */
    public static function wcFormatRequired(): bool
    {
        return static::get('wc_format_required') === '1';
    }

    /**
     * The number new RFQs start from, as set on Settings → RFQ Numbers — 1670
     * for RFQ1670: the next RFQ is given it, and they count on from there
     * (Rfq::nextRfqNumber()). Until it's set, one past Rfq::RFQ_NUMBER_START.
     * Kept within RFQ_NUMBER_START_RANGE.
     */
    public static function rfqNumberStart(): int
    {
        $saved = static::get('rfq_number_start');
        [$lowest, $highest] = self::RFQ_NUMBER_START_RANGE;

        return is_numeric($saved)
            ? max($lowest, min($highest, (int) $saved))
            : Rfq::RFQ_NUMBER_START + 1;
    }

    /**
     * When the automatic backups run, and whether they take the uploaded
     * files too — what was saved on the Backups page, or
     * DEFAULT_BACKUP_SCHEDULE for anything not saved (or not sensible).
     *
     * @return array{daily: array{enabled: bool, time: string}, weekly: array{enabled: bool, day: string, time: string}, include_files: bool}
     */
    public static function backupSchedule(): array
    {
        $saved = json_decode((string) static::get('backup_schedule'), true);
        $saved = is_array($saved) ? $saved : [];
        $defaults = self::DEFAULT_BACKUP_SCHEDULE;
        $time = fn (mixed $value, string $fallback) => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $fallback;
        $flag = fn (mixed $value, bool $fallback) => is_bool($value) ? $value : $fallback;

        return [
            'daily' => [
                'enabled' => $flag($saved['daily']['enabled'] ?? null, $defaults['daily']['enabled']),
                'time' => $time($saved['daily']['time'] ?? null, $defaults['daily']['time']),
            ],
            'weekly' => [
                'enabled' => $flag($saved['weekly']['enabled'] ?? null, $defaults['weekly']['enabled']),
                'day' => in_array($saved['weekly']['day'] ?? null, self::WEEKDAYS, true) ? $saved['weekly']['day'] : $defaults['weekly']['day'],
                'time' => $time($saved['weekly']['time'] ?? null, $defaults['weekly']['time']),
            ],
            'include_files' => $flag($saved['include_files'] ?? null, $defaults['include_files']),
        ];
    }

    /**
     * Saves the automatic backups' schedule — see backupSchedule().
     *
     * @param  array{daily: array{enabled: bool, time: string}, weekly: array{enabled: bool, day: string, time: string}, include_files: bool}  $schedule
     */
    public static function putBackupSchedule(array $schedule): void
    {
        static::put('backup_schedule', json_encode($schedule));
    }

    /**
     * Saves where new RFQ numbers start from — see rfqNumberStart().
     */
    public static function putRfqNumberStart(int $start): void
    {
        static::put('rfq_number_start', (string) $start);
    }

    /**
     * Saves the Data Entry idle alert — see dataEntryIdleAlert() for its shape.
     *
     * @param  array{enabled: bool, minutes: int}  $alert
     */
    public static function putDataEntryIdleAlert(array $alert): void
    {
        static::put('data_entry_idle_alert', json_encode($alert));
    }

    /**
     * Data Entry's target per item — whether it's on, and the working seconds
     * an item takes — as saved on the Settings page, or
     * DEFAULT_DATA_ENTRY_PER_ITEM, kept within DATA_ENTRY_PER_ITEM_RANGE.
     * While it's on, a part's target is that times its items
     * (Rfq::dataEntryTargetSeconds()); a part with no item count keeps its
     * priority's.
     *
     * @return array{enabled: bool, seconds: int}
     */
    public static function dataEntryPerItem(): array
    {
        $saved = json_decode((string) static::get('data_entry_per_item'), true);
        $saved = is_array($saved) ? $saved : [];
        [$fewest, $most] = self::DATA_ENTRY_PER_ITEM_RANGE;

        return [
            'enabled' => is_bool($saved['enabled'] ?? null) ? $saved['enabled'] : self::DEFAULT_DATA_ENTRY_PER_ITEM['enabled'],
            'seconds' => is_numeric($saved['seconds'] ?? null)
                ? max($fewest, min($most, (int) $saved['seconds']))
                : self::DEFAULT_DATA_ENTRY_PER_ITEM['seconds'],
        ];
    }

    /**
     * Saves Data Entry's target per item — see dataEntryPerItem().
     *
     * @param  array{enabled: bool, seconds: int}  $perItem
     */
    public static function putDataEntryPerItem(array $perItem): void
    {
        static::put('data_entry_per_item', json_encode($perItem));
    }

    /**
     * Data Entry's target per priority, in working minutes — what was saved
     * on the Settings page, or DEFAULT_DATA_ENTRY_TARGETS, kept within
     * TARGET_RANGE.
     *
     * @return array<string, int>
     */
    public static function dataEntryTargets(): array
    {
        return static::targets('data_entry_targets', self::DEFAULT_DATA_ENTRY_TARGETS);
    }

    /**
     * Finalize's target per priority, in working minutes — what was saved on
     * the Settings page, or DEFAULT_FINALIZE_TARGETS, kept within
     * TARGET_RANGE.
     *
     * @return array<string, int>
     */
    public static function finalizeTargets(): array
    {
        return static::targets('finalize_targets', self::DEFAULT_FINALIZE_TARGETS);
    }

    /**
     * The targets saved under $key — each priority's, in working minutes —
     * or $defaults for any not saved, kept within TARGET_RANGE.
     *
     * @param  array<string, int>  $defaults
     * @return array<string, int>
     */
    private static function targets(string $key, array $defaults): array
    {
        $saved = json_decode((string) static::get($key), true);
        $saved = is_array($saved) ? $saved : [];
        [$fewest, $most] = self::TARGET_RANGE;

        return collect($defaults)
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
     * A countdown to the second as a clock — "09:42", "1:05:00" from an hour
     * up; "00:00" once it's run out. Mirrored by admin.js.
     */
    public static function clockLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $clock = sprintf('%02d:%02d', intdiv($seconds % 3600, 60), $seconds % 60);

        return $hours > 0 ? $hours.':'.$clock : $clock;
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
