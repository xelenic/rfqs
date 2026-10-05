<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Senior Operations' daily attendance sheet (AttendanceSheet): made each day
 * with every Sourcing, Data Entry and GM Assistant person present, then
 * anyone off marked absent, with why. A person's time only counts on a day
 * the sheet has them present. For Admin and Senior Operations
 * (User::canManageAttendance()).
 */
class AttendanceController extends Controller
{
    /**
     * The sheet for a day — today unless another since attendance started is
     * asked for — or, with none made yet, the button to make it; and the
     * working days still without one.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations keep the attendance sheet.');

        $today = Setting::today();
        $since = Setting::attendanceSince();
        $asked = (string) $request->query('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) && $asked <= $today && ($since === null || $asked >= $since) ? $asked : $today;

        $sheet = AttendanceSheet::query()->with(['attendances', 'createdBy', 'updatedBy'])->where('date', $date)->first();
        $marks = $sheet?->attendances->keyBy('user_id') ?? collect();

        // Everyone who belongs on it now, and anyone already on it who no
        // longer does.
        $people = AttendanceSheet::people();
        $people = $people->merge(User::query()->with('roles')->whereIn('id', $marks->keys()->diff($people->pluck('id')))->get())->sortBy('name')->values();

        return view('admin.attendance.index', [
            'date' => $date,
            'today' => $today,
            'since' => $since,
            'sheet' => $sheet,
            'marks' => $marks,
            'people' => $people,
            'tracked' => AttendanceSheet::trackedOn($date),
            'missingDays' => AttendanceSheet::missingDays(),
            'recentSheets' => AttendanceSheet::query()->withCount([
                'attendances as present_count' => fn ($query) => $query->where('status', Attendance::PRESENT),
                'attendances as absent_count' => fn ($query) => $query->where('status', Attendance::ABSENT),
            ])->latest('date')->limit(10)->get(),
        ]);
    }

    /**
     * Makes a day's sheet, with everyone who belongs on it present — ready to
     * mark anyone who's off. A day that already has one is just opened.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations keep the attendance sheet.');

        $validated = $request->validateWithBag('attendance', ['date' => $this->dateRules()]);

        $sheet = AttendanceSheet::query()->firstOrCreate(['date' => $validated['date']], ['created_by' => $request->user()->id]);

        if ($sheet->wasRecentlyCreated) {
            foreach (AttendanceSheet::people() as $person) {
                $sheet->attendances()->create(['user_id' => $person->id, 'status' => Attendance::PRESENT]);
            }
        }

        return redirect()->route('admin.attendance.index', ['date' => $validated['date']])->with('status', $sheet->wasRecentlyCreated
            ? "Attendance sheet made for {$this->dayLabel($validated['date'])} — everyone's present until you mark them absent."
            : "There's already a sheet for {$this->dayLabel($validated['date'])}.");
    }

    /**
     * Saves who was present and who was absent — and why — on a day's sheet.
     */
    public function update(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations keep the attendance sheet.');

        $belongs = AttendanceSheet::people()->pluck('id')->merge($sheet->attendances()->pluck('user_id'))->unique()->all();

        $validated = $request->validateWithBag('attendance', [
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', Rule::in([Attendance::PRESENT, Attendance::ABSENT])],
            'attendance.*.reason' => ['nullable', 'required_if:attendance.*.status,'.Attendance::ABSENT, Rule::in(Attendance::REASONS)],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
        ], [
            'attendance.*.reason.required_if' => 'Say why they were absent.',
        ]);

        foreach ($validated['attendance'] as $userId => $mark) {
            abort_unless(in_array((int) $userId, $belongs, true), 422, 'Only Sourcing, Data Entry and GM Assistant people go on the sheet.');

            $present = $mark['status'] === Attendance::PRESENT;

            $sheet->attendances()->updateOrCreate(['user_id' => (int) $userId], [
                'status' => $mark['status'],
                'reason' => $present ? null : $mark['reason'],
                'note' => $present ? null : ($mark['note'] ?? null),
            ]);
        }

        $sheet->update(['updated_by' => $request->user()->id]);

        $absent = collect($validated['attendance'])->where('status', Attendance::ABSENT)->count();
        $present = count($validated['attendance']) - $absent;
        $date = $sheet->date->toDateString();

        return redirect()->route('admin.attendance.index', ['date' => $date])
            ->with('status', "Attendance saved for {$this->dayLabel($date)} — {$present} present, {$absent} absent.");
    }

    /**
     * A day a sheet can be made for: since attendance started, and not after
     * today.
     *
     * @return array<int, string>
     */
    private function dateRules(): array
    {
        return [
            'required',
            'date_format:Y-m-d',
            'before_or_equal:'.Setting::today(),
            'after_or_equal:'.(Setting::attendanceSince() ?? '1970-01-01'),
        ];
    }

    /**
     * "today", or the date — how a day reads in a status message.
     */
    private function dayLabel(string $date): string
    {
        return $date === Setting::today() ? 'today' : $date;
    }
}
