<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RfqStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch a part of an RFQ spent at a timed step — from when it reached
 * the step to when it left it, whether done or sent back. A part goes through
 * a step once per round, so rework is more rows; an open row (no ended_at) is
 * where the part is now — and a part stopped part way through (the RFQ on
 * hold or cancelled) carries on the same round once it's resumed (resumed),
 * rather than starting a new one. One the role ended themselves, outside working
 * hours, is work done out of hours (isOutOfHoursWork()). Its time is credited
 * to worked_by, and counts on a day the attendance sheet has them present
 * (Attendance) — see secondsByAttendance(). Kept by Rfq::syncSteps(); read as working time by
 * workingMinutes(), and per role by Rfq::timeSpent().
 */
class RfqStep extends Model
{
    /** @use HasFactory<RfqStepFactory> */
    use HasFactory;

    /**
     * The roles whose time is tracked, with the steps that are theirs:
     * Sourcing's own work and their Finalize, Data Entry's, GM Assistant's.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_STEPS = [
        'Sourcing' => ['sourcing', 'finalize'],
        'Data Entry' => ['data_entry'],
        'GM Assistant' => ['gm_assistant'],
    ];

    public $timestamps = false;

    protected $fillable = [
        'rfq_id',
        'part_number',
        'assignee_id',
        'worked_by',
        'step',
        'started_at',
        'ended_at',
        'ended_by_role',
        'resumed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'ended_by_role' => 'boolean',
            'resumed' => 'boolean',
        ];
    }

    /**
     * The role whose time this is — see ROLE_STEPS.
     */
    public function role(): ?string
    {
        foreach (self::ROLE_STEPS as $role => $steps) {
            if (in_array($this->step, $steps, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The working minutes this stretch has taken — up to $now while it's
     * still open. See Setting::workingMinutesBetween().
     */
    public function workingMinutes(?CarbonInterface $now = null): int
    {
        return intdiv($this->workingSeconds($now), 60);
    }

    /**
     * workingMinutes(), to the second.
     */
    public function workingSeconds(?CarbonInterface $now = null): int
    {
        return Setting::workingSecondsBetween($this->started_at, $this->ended_at ?? $now ?? now());
    }

    /**
     * The clock time this stretch has taken, working hours or not — up to
     * $now while it's still open.
     */
    public function elapsedSeconds(?CarbonInterface $now = null): int
    {
        return max(0, (int) $this->started_at->diffInSeconds($this->ended_at ?? $now ?? now(), false));
    }

    /**
     * Whether this stretch is work done out of hours: the role ended it
     * themselves — completed it, sent it on, sent it back — at a time outside
     * the working hours. A part that just sat overnight or over a weekend
     * isn't; nor is one ended by something else, like the RFQ being held.
     */
    public function isOutOfHoursWork(): bool
    {
        return $this->ended_by_role
            && $this->ended_at !== null
            && ! Setting::isWorkingTime($this->ended_at);
    }

    /**
     * How much of an out-of-hours stretch fell outside the working hours —
     * its clock time less its working time. None for any other stretch.
     */
    public function outOfHoursSeconds(): int
    {
        return $this->isOutOfHoursWork()
            ? max(0, $this->elapsedSeconds() - $this->workingSeconds())
            : 0;
    }

    /**
     * This stretch's working seconds by where whoever it's credited to
     * (worked_by) stood on each day of it (Attendance::statusIn()): present —
     * it counts — absent, or unmarked, that day's sheet not made yet (or not
     * approved); on a half day, what of it fell in the half they were off
     * (Setting::halfDayOff()) as absent and the rest as present. A
     * stretch that ended without anyone being credited with it — held or
     * freed before Data Entry or GM Assistant got to it — is unattributed
     * instead: nobody will be. Up to $now while it's still open.
     *
     * @param  array<int, array<string, string>>  $book  Attendance::book()
     * @return array{present: int, absent: int, unmarked: int, unattributed: int}
     */
    public function secondsByAttendance(array $book, ?CarbonInterface $now = null): array
    {
        $split = ['present' => 0, 'absent' => 0, 'unmarked' => 0, 'unattributed' => 0];

        foreach (Setting::workingSecondsWithHalfDaysOff($this->started_at, $this->ended_at ?? $now ?? now()) as $date => $seconds) {
            $status = Attendance::statusIn($book, $this->worked_by, $date);

            if ($status === Attendance::UNMARKED && $this->worked_by === null && $this->ended_at !== null) {
                $status = 'unattributed';
            }

            $off = match ($status) {
                Attendance::MORNING_OFF => $seconds['morning'],
                Attendance::AFTERNOON_OFF => $seconds['afternoon'],
                default => null,
            };

            if ($off === null) {
                $split[$status] += $seconds['all'];
            } else {
                $split['absent'] += $off;
                $split['present'] += $seconds['all'] - $off;
            }
        }

        return $split;
    }

    /**
     * This stretch's working seconds less any day its person was absent —
     * what counts against a deadline, which still ticks on a day whose sheet
     * isn't made yet.
     *
     * @param  array<int, array<string, string>>  $book  Attendance::book()
     */
    public function secondsExcludingAbsence(array $book, ?CarbonInterface $now = null): int
    {
        $split = $this->secondsByAttendance($book, $now);

        return $split['present'] + $split['unmarked'] + $split['unattributed'];
    }

    /**
     * Who the stretch's time is credited to — see worked_by.
     */
    public function workedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worked_by');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * The Sourcing member who held the part at the time.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
