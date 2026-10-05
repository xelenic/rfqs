<?php

namespace App\Models;

use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's line on a day's attendance sheet (AttendanceSheet): present —
 * the time tracked as theirs that day (RfqStep::worked_by) counts — or absent,
 * and why, so it doesn't. A day nobody's marked them for is unmarked, and
 * doesn't count yet either. Read through book() and statusIn().
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

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
        'reason',
        'note',
    ];

    /**
     * Everyone's attendance on every sheet, as user id => date ("Y-m-d") =>
     * status — for the users given, or everyone. What statusIn() reads.
     *
     * @param  iterable<int|null>|null  $userIds
     * @return array<int, array<string, string>>
     */
    public static function book(?iterable $userIds = null): array
    {
        $book = [];

        static::query()
            ->join('attendance_sheets', 'attendance_sheets.id', '=', 'attendances.attendance_sheet_id')
            ->when($userIds !== null, fn ($query) => $query->whereIn('attendances.user_id', collect($userIds)->filter()->unique()->all()))
            ->get(['attendances.user_id', 'attendance_sheets.date', 'attendances.status'])
            ->each(function (Attendance $attendance) use (&$book) {
                $book[$attendance->user_id][substr((string) $attendance->date, 0, 10)] = $attendance->status;
            });

        return $book;
    }

    /**
     * Where $userId stands on $date in $book: present, absent, or unmarked —
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
