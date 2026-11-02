<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SourcingDayFigure;
use App\PerformanceReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Sourcing Summary report's typed-in figures (SourcingDayFigure): a
 * Sourcing member's own supplier calls, from their dashboard; and, from the
 * report, on one day, everyone's calls and expected RFQs — by Senior Operations
 * and Admin alone (User::canEditSourcingFigures()).
 */
class SourcingDayFigureController extends Controller
{
    /**
     * A Sourcing member enters their own supplier calls for a day, today or
     * earlier. Never anyone else's, nor their expected RFQs.
     */
    public function updateOwnCalls(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Sourcing'), 403, 'Only someone in Sourcing has calls of their own to enter.');

        $validated = $request->validateWithBag('sourcingCalls', [
            'day' => $this->dayRules(),
            ...collect(SourcingDayFigure::CALL_FIELDS)->map(fn () => $this->figureRules())->all(),
        ], [
            '*.integer' => 'Give each count as a whole number, like 5.',
            '*.min' => 'A count can\'t be less than 0.',
            '*.max' => 'That\'s more than a day can hold — at most '.number_format(SourcingDayFigure::MAX_FIGURE).'.',
            'day.*' => 'Pick today or an earlier day.',
        ]);

        SourcingDayFigure::record($request->user()->id, $validated['day'], $this->numbers($validated, array_keys(SourcingDayFigure::CALL_FIELDS)));

        return back()->with('status', 'Saved your supplier calls for '.CarbonImmutable::parse($validated['day'])->format('M d, Y').'.');
    }

    /**
     * Senior Operations or Admin sets one day's figures on the report:
     * expected RFQs, and calls to correct what a member entered. Only for
     * people in Sourcing, and only those the report showed. A figure left
     * blank is cleared.
     */
    public function updateForDay(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canEditSourcingFigures(), 403, 'Only Senior Operations and Admin can change these figures.');

        $fields = [...array_keys(SourcingDayFigure::CALL_FIELDS), 'expected_rfqs'];
        $validated = $request->validate([
            'day' => $this->dayRules(),
            'figures' => ['required', 'array'],
            ...collect($fields)->mapWithKeys(fn (string $field) => ["figures.*.{$field}" => $this->figureRules()])->all(),
        ], [
            'figures.*.*.integer' => 'Give each figure as a whole number, like 5.',
            'figures.*.*.min' => 'A figure can\'t be less than 0.',
            'figures.*.*.max' => 'That\'s more than a day can hold — at most '.number_format(SourcingDayFigure::MAX_FIGURE).'.',
            'day.*' => 'Pick today or an earlier day.',
        ]);

        foreach (PerformanceReports::people('Sourcing')->whereIn('id', array_keys($validated['figures'])) as $person) {
            SourcingDayFigure::record($person->id, $validated['day'], $this->numbers($validated['figures'][$person->id], $fields));
        }

        return back()->with('status', 'Saved the figures for '.CarbonImmutable::parse($validated['day'])->format('M d, Y').'.');
    }

    /**
     * A day, today or earlier, in the business's time zone.
     *
     * @return array<int, string>
     */
    private function dayRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:'.Setting::today()];
    }

    /**
     * @return array<int, string>
     */
    private function figureRules(): array
    {
        return ['nullable', 'integer', 'min:0', 'max:'.SourcingDayFigure::MAX_FIGURE];
    }

    /**
     * $fields out of what was sent, as numbers — null where left blank.
     *
     * @param  array<string, mixed>  $sent
     * @param  array<int, string>  $fields
     * @return array<string, int|null>
     */
    private function numbers(array $sent, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $field) => [
            $field => isset($sent[$field]) ? (int) $sent[$field] : null,
        ])->all();
    }
}
