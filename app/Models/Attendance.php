<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's line on a day's attendance sheet (AttendanceSheet): present —
 * the time tracked as theirs that day (RfqStep::worked_by) counts — absent
 * (on leave), so it doesn't — or a half day: off the morning or the
 * afternoon (half_off), so the time that half takes
 * (Setting::halfDayOff()) doesn't count and the rest of the day does — or
 * off a custom time instead (CUSTOM_HALF, half_off_start to half_off_end,
 * up to MAX_CUSTOM_OFF_SECONDS of their working time) — with
 * a note if there's anything to say. (Why they were off isn't asked any
 * more; an older line keeps the reason it was given.) It
 * only stands once HR Executive has
 * approved the sheet; until then — like a day nobody's marked them for — the
 * day is unmarked, and doesn't count yet either. Read through book() and
 * statusIn().
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const HALF_DAY = 'half_day';

    /**
     * Which half of a half day someone was off, with how it reads.
     *
     * @var array<string, string>
     */
    public const HALVES = ['morning' => 'Morning off', 'afternoon' => 'Afternoon off'];

    /**
     * A half day off a time of the marker's choosing (half_off_start to
     * half_off_end) rather than the morning or the afternoon.
     */
    public const CUSTOM_HALF = 'custom';

    /**
     * The most working time a custom half day off can take: 4 hours.
     */
    public const MAX_CUSTOM_OFF_SECONDS = 4 * 3600;

    /**
     * How book() and statusIn() have a half day off a custom time: this,
     * then "HH:MM-HH:MM" (customOffStatus()).
     */
    public const CUSTOM_OFF_PREFIX = 'custom_off:';

    /**
     * A half day off the morning, or off the afternoon — as book() and
     * statusIn() have a half day.
     */
    public const MORNING_OFF = 'morning_off';

    public const AFTERNOON_OFF = 'afternoon_off';

    public const UNMARKED = 'unmarked';

    protected $fillable = [
        'attendance_sheet_id',
        'user_id',
        'status',
        'half_off',
        'half_off_start',
        'half_off_end',
        'reason',
        'note',
    ];

    /**
     * Everyone's attendance on every sheet HR Executive has approved, as user
     * id => date ("Y-m-d") => status — a half day as the half they were off
     * (MORNING_OFF, AFTERNOON_OFF) or the custom time (customOffStatus()) —
     * for the users given, or everyone. A
     * sheet still awaiting approval, or returned, isn't in it: its day is
     * unmarked until it's approved. What statusIn() reads.
     *
     * @param  iterable<int|null>|null  $userIds
     * @return array<int, array<string, string>>
     */
    public static function book(?iterable $userIds = null): array
    {
        $book = [];

        static::query()
            ->join('attendance_sheets', 'attendance_sheets.id', '=', 'attendances.attendance_sheet_id')
            ->whereNotNull('attendance_sheets.approved_at')
            ->when($userIds !== null, fn ($query) => $query->whereIn('attendances.user_id', collect($userIds)->filter()->unique()->all()))
            ->get(['attendances.user_id', 'attendance_sheets.date', 'attendances.status', 'attendances.half_off', 'attendances.half_off_start', 'attendances.half_off_end'])
            ->each(function (Attendance $attendance) use (&$book) {
                $book[$attendance->user_id][substr((string) $attendance->date, 0, 10)] = match (true) {
                    $attendance->status !== self::HALF_DAY => $attendance->status,
                    $attendance->half_off === 'morning' => self::MORNING_OFF,
                    $attendance->half_off === self::CUSTOM_HALF => self::customOffStatus($attendance->half_off_start, $attendance->half_off_end),
                    default => self::AFTERNOON_OFF,
                };
            });

        return $book;
    }

    /**
     * Where $userId stands on $date in $book: present, absent, a half day
     * (MORNING_OFF, AFTERNOON_OFF, or a custom time — timeOffOf()), or
     * unmarked —
     * and present, no sheet needed, on a day before attendance started
     * (Setting::attendanceSince()) or while it's off. Time nobody's been
     * credited with yet ($userId null) is unmarked.
     *
     * @param  array<int, array<string, string>>  $book
     */
    public static function statusIn(array $book, ?int $userId, string $date): string
    {
        $since = Setting::attendanceSince();

        if ($since === null || $date < $since) {
            return self::PRESENT;
        }

        if ($userId === null) {
            return self::UNMARKED;
        }

        return $book[$userId][$date] ?? self::UNMARKED;
    }

    /**
     * How much time off each of $userIds has taken — leaves (a whole day
     * off) and half days — this month, this year and since attendance
     * started, on the sheets HR Executive has approved, with the last day
     * of each; and how many more are on a sheet still awaiting approval (or
     * returned), which don't count yet. As user id => tally — everyone
     * given, with nothing taken if so. What a person's page and the hover
     * on the attendance sheets show.
     *
     * @param  iterable<int|null>  $userIds
     * @return array<int, array{month: array{leaves: int, half_days: int}, year: array{leaves: int, half_days: int}, all: array{leaves: int, half_days: int}, pending: array{leaves: int, half_days: int}, last_leave: ?string, last_half_day: ?string}>
     */
    public static function timeOffTally(iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values()->all();
        $none = ['leaves' => 0, 'half_days' => 0];
        $tally = array_fill_keys($ids, ['month' => $none, 'year' => $none, 'all' => $none, 'pending' => $none, 'last_leave' => null, 'last_half_day' => null]);

        if ($ids === []) {
            return [];
        }

        $today = Setting::today();

        static::query()
            ->join('attendance_sheets', 'attendance_sheets.id', '=', 'attendances.attendance_sheet_id')
            ->whereIn('attendances.user_id', $ids)
            ->whereIn('attendances.status', [self::ABSENT, self::HALF_DAY])
            ->orderBy('attendance_sheets.date')
            ->get(['attendances.user_id', 'attendances.status', 'attendance_sheets.date', 'attendance_sheets.approved_at'])
            ->each(function (Attendance $line) use (&$tally, $today) {
                $date = substr((string) $line->date, 0, 10);
                $kind = $line->status === self::ABSENT ? 'leaves' : 'half_days';

                if ($line->approved_at === null) {
                    $tally[$line->user_id]['pending'][$kind]++;

                    return;
                }

                $tally[$line->user_id]['all'][$kind]++;
                $tally[$line->user_id][$kind === 'leaves' ? 'last_leave' : 'last_half_day'] = $date;

                if (substr($date, 0, 4) === substr($today, 0, 4)) {
                    $tally[$line->user_id]['year'][$kind]++;
                }

                if (substr($date, 0, 7) === substr($today, 0, 7)) {
                    $tally[$line->user_id]['month'][$kind]++;
                }
            });

        return $tally;
    }

    /**
     * The days off a tally's counts come to — a leave a day, a half day
     * half of one: "3", "4.5".
     *
     * @param  array{leaves: int, half_days: int}  $counts
     */
    public static function daysOffLabel(array $counts): string
    {
        $days = $counts['leaves'] + $counts['half_days'] / 2;

        return fmod($days, 1.0) === 0.0 ? (string) (int) $days : number_format($days, 1);
    }

    /**
     * $userId's leaves and half days on the sheets from $from ("Y-m-d") on,
     * latest first, approved or not — each with its sheet (sheet).
     *
     * @return Collection<int, Attendance>
     */
    public static function timeOffSince(int $userId, string $from): Collection
    {
        return static::query()
            ->select('attendances.*')
            ->join('attendance_sheets', 'attendance_sheets.id', '=', 'attendances.attendance_sheet_id')
            ->where('attendances.user_id', $userId)
            ->whereIn('attendances.status', [self::ABSENT, self::HALF_DAY])
            ->whereDate('attendance_sheets.date', '>=', $from)
            ->orderByDesc('attendance_sheets.date')
            ->with('sheet')
            ->get();
    }

    /**
     * $userId's line on $date's sheet ("Y-m-d"), approved or not — what
     * Senior Operations has marked so far, which is enough to know who's on
     * leave today (User::dataEntryIdleness()). Null if there's none.
     */
    public static function markOn(int $userId, string $date): ?self
    {
        return static::query()
            ->join('attendance_sheets', 'attendance_sheets.id', '=', 'attendances.attendance_sheet_id')
            ->whereDate('attendance_sheets.date', $date)
            ->where('attendances.user_id', $userId)
            ->first(['attendances.user_id', 'attendances.status', 'attendances.half_off', 'attendances.half_off_start', 'attendances.half_off_end']);
    }

    /**
     * How book() has a half day off from $start to $end ("HH:MM").
     */
    public static function customOffStatus(string $start, string $end): string
    {
        return self::CUSTOM_OFF_PREFIX."{$start}-{$end}";
    }

    /**
     * The time a status in book() has them off — the morning or the
     * afternoon as Settings has it (Setting::halfDayOff()), or a custom
     * time — or null for one that isn't a half day.
     *
     * @return array{start: string, end: string}|null
     */
    public static function timeOffOf(string $status): ?array
    {
        if (str_starts_with($status, self::CUSTOM_OFF_PREFIX)) {
            [$start, $end] = explode('-', substr($status, strlen(self::CUSTOM_OFF_PREFIX))) + [null, null];

            return $start !== null && $end !== null ? ['start' => $start, 'end' => $end] : null;
        }

        return match ($status) {
            self::MORNING_OFF => Setting::halfDayOff()['morning'],
            self::AFTERNOON_OFF => Setting::halfDayOff()['afternoon'],
            default => null,
        };
    }

    /**
     * How a half day status in book() reads: "Morning off", "Afternoon off",
     * "Off 10:00–14:00" — null for any other.
     */
    public static function timeOffLabelOf(string $status): ?string
    {
        return match (true) {
            $status === self::MORNING_OFF => self::HALVES['morning'],
            $status === self::AFTERNOON_OFF => self::HALVES['afternoon'],
            ($custom = self::timeOffOf($status)) !== null => "Off {$custom['start']}–{$custom['end']}",
            default => null,
        };
    }

    /**
     * The time this line has them off on a half day — as timeOffOf() — or
     * null when it isn't one.
     *
     * @return array{start: string, end: string}|null
     */
    public function timeOff(): ?array
    {
        if ($this->status !== self::HALF_DAY) {
            return null;
        }

        return match ($this->half_off) {
            self::CUSTOM_HALF => $this->half_off_start !== null && $this->half_off_end !== null
                ? ['start' => $this->half_off_start, 'end' => $this->half_off_end]
                : null,
            'morning', 'afternoon' => Setting::halfDayOff()[$this->half_off],
            default => null,
        };
    }

    /**
     * How this line's half day reads — "Morning off", "Afternoon off",
     * "Off 10:00–14:00" — or null when it isn't one.
     */
    public function timeOffLabel(): ?string
    {
        if ($this->status !== self::HALF_DAY) {
            return null;
        }

        if ($this->half_off === self::CUSTOM_HALF) {
            return "Off {$this->half_off_start}–{$this->half_off_end}";
        }

        return self::HALVES[$this->half_off] ?? 'Half off';
    }

    /**
     * What kind of leave this line is, as HR Executive reads it before
     * approving: "Full-Day Leave", "Half-Day Leave – First Half" (the
     * morning off), "Half-Day Leave – Second Half" (the afternoon) or
     * "Half-Day Leave – Custom Time" — null for someone present.
     */
    public function leaveTypeLabel(): ?string
    {
        return match (true) {
            $this->status === self::ABSENT => 'Full-Day Leave',
            $this->status !== self::HALF_DAY => null,
            $this->half_off === 'morning' => 'Half-Day Leave – First Half',
            $this->half_off === 'afternoon' => 'Half-Day Leave – Second Half',
            default => 'Half-Day Leave – Custom Time',
        };
    }

    /**
     * The hours this line has them off on $date ("Y-m-d", its sheet's day):
     * their whole working day on a leave (Setting::weekOn(), their own
     * week), the half or the custom time on a half day — null for someone
     * present, or a leave on a day they don't work.
     *
     * @return array{start: string, end: string}|null
     */
    public function hoursOff(string $date): ?array
    {
        if ($this->status !== self::ABSENT) {
            return $this->timeOff();
        }

        $day = Setting::weekOn($date, $this->user_id)[strtolower(CarbonImmutable::parse($date)->format('l'))] ?? ['working' => false];

        return $day['working'] ? ['start' => $day['start'], 'end' => $day['end']] : null;
    }

    /**
     * How much of their working time this line has them off on $date
     * ("Y-m-d", its sheet's day), in their own hours with lunch left out:
     * the whole working day on a leave, the half's (or custom time's)
     * working part on a half day — 0 for someone present.
     */
    public function workingSecondsOff(string $date): int
    {
        $dayStart = CarbonImmutable::parse($date, Setting::timezone())->startOfDay();
        $dayEnd = $dayStart->addDay();

        return match (true) {
            $this->status === self::ABSENT => Setting::workingSecondsBetween($dayStart, $dayEnd, $this->user_id),
            ($off = $this->timeOff()) !== null => Setting::workingSecondsInTimeOff($dayStart, $dayEnd, $date, $off, $this->user_id),
            default => 0,
        };
    }

    /**
     * The day's sheet it's on.
     */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(AttendanceSheet::class, 'attendance_sheet_id');
    }

    /**
     * Whose attendance it is.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
