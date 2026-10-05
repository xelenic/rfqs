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
 * and GM Assistant person, present or absent (Attendance). Made fresh each
 * day — everyone present until marked otherwise — and kept up to date after.
 * From the day attendance starts (Setting::attendanceSince()), a person's
 * time only counts on a day the sheet has them present.
 */
class AttendanceSheet extends Model
{
    /** @use HasFactory<AttendanceSheetFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
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
}
