<?php

namespace App\Models;

use Database\Factories\SourcingDayFigureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a Sourcing member's day had that the workflow doesn't record, typed in
 * by hand for the Sourcing Summary report (App\PerformanceReports::sourcingSummary()):
 * their supplier calls, which they enter themselves on their dashboard, and the
 * RFQs Senior Operations expected of them, which only Senior Operations and
 * Admin set (Admin\SourcingDayFigureController). A figure not given is null.
 *
 * day stays a plain "Y-m-d" string, with no date cast: a cast stores it with a
 * time on SQLite, and it's compared as a string.
 */
class SourcingDayFigure extends Model
{
    /** @use HasFactory<SourcingDayFigureFactory> */
    use HasFactory;

    /**
     * The calls a member enters themselves, with how each reads.
     *
     * @var array<string, string>
     */
    public const CALL_FIELDS = [
        'successful_calls' => 'No of successful calls made to suppliers',
        'unanswered_calls' => 'No of unanswered calls made to suppliers',
        'unattended_calls' => 'Unattended calls from supplier',
    ];

    /**
     * The most any one figure can be, in a day.
     */
    public const MAX_FIGURE = 100000;

    protected $fillable = [
        'user_id',
        'day',
        'successful_calls',
        'unanswered_calls',
        'unattended_calls',
        'expected_rfqs',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful_calls' => 'integer',
            'unanswered_calls' => 'integer',
            'unattended_calls' => 'integer',
            'expected_rfqs' => 'integer',
        ];
    }

    /**
     * Sets $figures on $userId's $day, leaving its other figures as they are.
     * Writes nothing when there's nothing to change, nor a row of nothing but
     * blanks.
     *
     * @param  array<string, int|null>  $figures
     */
    public static function record(int $userId, string $day, array $figures): void
    {
        $row = static::query()->firstOrNew(['user_id' => $userId, 'day' => $day]);

        if (! $row->exists && array_filter($figures, fn (?int $figure) => $figure !== null) === []) {
            return;
        }

        $row->fill($figures)->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
