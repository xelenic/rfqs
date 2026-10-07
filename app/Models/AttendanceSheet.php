<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceSheetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Senior Operations' attendance sheet for one day: every Sourcing, Data Entry
 * and GM Assistant person, present or on leave (Attendance). Made fresh each
 * day — everyone present until marked otherwise — and kept up to date after.
 * Each one submitted goes to HR Manager, who approves it or returns it to
 * Senior Operations, with why, to correct and submit again (submitted(),
 * approve(), sendBack()). From the day attendance starts
 * (Setting::attendanceSince()), a person's time only counts on a day whose
 * approved sheet has them present — see Attendance::book().
 */
class AttendanceSheet extends Model
{
    /** @use HasFactory<AttendanceSheetFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
        'returned_by',
        'returned_at',
        'return_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * Whether HR Manager has approved it — what makes its day count.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Whether HR Manager has returned it to Senior Operations, still to be
     * corrected and submitted again.
     */
    public function isReturned(): bool
    {
        return ! $this->isApproved() && $this->returned_at !== null;
    }

    /**
     * Whether it's waiting on HR Manager's review.
     */
    public function isAwaitingApproval(): bool
    {
        return ! $this->isApproved() && ! $this->isReturned();
    }

    /**
     * Senior Operations has submitted it — new, or corrected — so it's
     * HR Manager's to review again: any approval or return before goes.
     */
    public function submitted(User $by): void
    {
        $this->update([
            'updated_by' => $by->id,
            'approved_by' => null,
            'approved_at' => null,
            'returned_by' => null,
            'returned_at' => null,
            'return_reason' => null,
        ]);
    }

    /**
     * HR Manager approves it: from now its day's attendance counts — present
     * people's time, and none of anyone's on leave.
     */
    public function approve(User $by): void
    {
        $this->update([
            'approved_by' => $by->id,
            'approved_at' => now(),
            'returned_by' => null,
            'returned_at' => null,
            'return_reason' => null,
        ]);
    }

    /**
     * HR Manager returns it to Senior Operations, with why, to correct and
     * submit again.
     */
    public function sendBack(User $by, string $reason): void
    {
        $this->update([
            'returned_by' => $by->id,
            'returned_at' => now(),
            'return_reason' => $reason,
        ]);
    }

    /**
     * How many sheets are waiting on HR Manager's review.
     */
    public static function awaitingApprovalCount(): int
    {
        return static::query()->whereNull('approved_at')->whereNull('returned_at')->count();
    }

    /**
     * How many sheets HR Manager has returned to Senior Operations, still to
     * be corrected.
     */
    public static function returnedCount(): int
    {
        return static::query()->whereNull('approved_at')->whereNotNull('returned_at')->count();
    }

    /**
     * Everyone who belongs on a sheet — the people whose time is tracked
     * (RfqStep::ROLE_STEPS) — by name.
     *
     * @return Collection<int, User>
     */
    public static function people(): Collection
    {
        return User::role(array_keys(RfqStep::ROLE_STEPS))->with('roles')->orderBy('name')->get();
    }

    /**
     * The working days since attendance started (Setting::attendanceSince()),
     * up to today, with no sheet made yet — newest first. A day off needs
     * none: nobody's time counts on it anyway.
     *
     * @return array<int, string>
     */
    public static function missingDays(): array
    {
        $since = Setting::attendanceSince();

        if ($since === null) {
            return [];
        }

        $week = Setting::workingHours();
        $made = static::query()->where('date', '>=', $since)->pluck('date')->map(fn ($date) => $date->toDateString())->all();
        $missing = [];

        for ($day = CarbonImmutable::parse(Setting::today()); $day->toDateString() >= $since; $day = $day->subDay()) {
            if ($week[strtolower($day->englishDayOfWeek)]['working'] && ! in_array($day->toDateString(), $made, true)) {
                $missing[] = $day->toDateString();
            }
        }

        return $missing;
    }

    /**
     * Everyone's line on the sheet.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Who made the sheet.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who last changed it.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The HR Manager who approved it.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The HR Manager who returned it.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
