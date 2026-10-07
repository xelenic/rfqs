<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's line on a day's attendance sheet (AttendanceSheet): present —
 * the time tracked as theirs that day (RfqStep::worked_by) counts — absent
 * (on leave), and why, so it doesn't — or a half day: off the morning or the
 * afternoon (half_off), and why, so the time that half takes
 * (Setting::halfDayOff()) doesn't count and the rest of the day does. It
 * only stands once HR Manager has
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
     * A half day off the morning, or off the afternoon — as book() and
     * statusIn() have a half day.
     */
    public const MORNING_OFF = 'morning_off';

    public const AFTERNOON_OFF = 'afternoon_off';

    public const UNMARKED = 'unmarked';

    /**
     * Why someone was absent.
     *
     * @var array<int, string>
     */
    public const REASONS = ['Casual leave', 'Sick leave', 'Personal leave', 'Other'];

    protected $fillable = [
        'attendance_sheet_id',
        'user_id',
        'status',
        'half_off',
        'reason',
        'note',
    ];

    /**
     * Everyone's attendance on every sheet HR Manager has approved, as user
     * id => date ("Y-m-d") => status — a half day as the half they were off
     * (MORNING_OFF, AFTERNOON_OFF) — for the users given, or everyone. A
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
            ->get(['attendances.user_id', 'attendance_sheets.date', 'attendances.status', 'attendances.half_off'])
            ->each(function (Attendance $attendance) use (&$book) {
                $book[$attendance->user_id][substr((string) $attendance->date, 0, 10)] = match (true) {
                    $attendance->status !== self::HALF_DAY => $attendance->status,
                    $attendance->half_off === 'morning' => self::MORNING_OFF,
                    default => self::AFTERNOON_OFF,
                };
            });

        return $book;
    }

    /**
     * Where $userId stands on $date in $book: present, absent, a half day
     * (MORNING_OFF, AFTERNOON_OFF), or unmarked —
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
