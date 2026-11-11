<?php

namespace App\Models;

use Closure;
use Database\Factories\SourcingExpectedRfqFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many RFQs a Sourcing member is usually expected to hand in a working
 * day, from starts_on on — set by an Admin on their user page
 * (Admin\UserController::updateExpectedRfqs()). The Sourcing Summary uses it
 * on any working day of theirs nobody typed a figure in for
 * (App\PerformanceReports::expectedRfqs()). Versions are kept, so a change
 * never rewrites past days; null is "none from then on".
 *
 * starts_on stays a plain "Y-m-d" string, with no date cast, compared as a
 * string — as SourcingDayFigure's day.
 */
class SourcingExpectedRfq extends Model
{
    /** @use HasFactory<SourcingExpectedRfqFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'expected_rfqs',
        'starts_on',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'expected_rfqs' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * The starts_on of a person's first count: it counts on every day before
     * too, so the report shows it for past days as soon as it's set.
     */
    public const FROM_THE_START = '2000-01-01';

    /**
     * Sets $userId's usual count, as saved on their user page: the first one
     * from the start (FROM_THE_START), and any change from today on — so
     * past days keep the count they had. Changed twice in a day, the day's
     * version changes in place.
     */
    public static function setFor(int $userId, ?int $expected, ?int $setBy): self
    {
        $startsOn = static::query()->where('user_id', $userId)->exists() ? Setting::today() : self::FROM_THE_START;

        return static::put($userId, $expected, $startsOn, $setBy);
    }

    /**
     * Sets $userId's usual count from $startsOn on — changing in place a
     * version that starts that same day.
     */
    public static function put(int $userId, ?int $expected, string $startsOn, ?int $setBy): self
    {
        return static::query()->updateOrCreate(
            ['user_id' => $userId, 'starts_on' => $startsOn],
            ['expected_rfqs' => $expected, 'set_by' => $setBy],
        );
    }

    /**
     * The usual counts of $userIds, day after day: a function from a user id
     * and a "Y-m-d" to the count that applies then (their latest version
     * starting on or before it), or null. One query for them all.
     *
     * @param  array<int, int>  $userIds
     * @return Closure(int, string): ?int
     */
    public static function lookup(array $userIds): Closure
    {
        $versions = static::query()->whereIn('user_id', $userIds)->orderByDesc('starts_on')->get()->groupBy('user_id');

        return fn (int $userId, string $day): ?int => $versions->get($userId)?->first(fn (self $version) => $version->starts_on <= $day)?->expected_rfqs;
    }
}
