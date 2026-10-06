<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Senior Operations' daily attendance sheets (AttendanceSheet), one a day:
 * every day's submitted sheet listed, newest first, and a new one — or a
 * correction to one already submitted — filled in a popup: every Sourcing, Data Entry and GM
 * Assistant person, present or on leave, with why when on leave. A person's
 * time only counts on a day the sheet has them present. For Admin, Senior
 * Operations and HR Manager (User::canManageAttendance()).
 */
class AttendanceController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Why a second sheet for a day is turned away.
     */
    private const ONE_A_DAY = 'There\'s already a sheet for that day — only one per day. Edit it from the list.';

    /**
     * The submitted sheets, day by day, and the working days still without
     * one — with the popup to fill a new one in.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin, Senior Operations and HR Manager keep the attendance sheet.');

        $today = Setting::today();
        $missingDays = AttendanceSheet::missingDays();
        // One sheet a day: every day that has one, so the popup can turn a
        // second away before it's filled in.
        $sheetDates = AttendanceSheet::query()->pluck('date')->map(fn ($date) => $date->toDateString())->all();

        return view('admin.attendance.index', [
            'sheets' => AttendanceSheet::query()
                ->with(['attendances.user', 'createdBy', 'updatedBy'])
                ->latest('date')
                ->paginate(self::PER_PAGE),
            'people' => AttendanceSheet::people(),
            'missingDays' => $missingDays,
            'sheetDates' => $sheetDates,
            // What New attendance sheet opens on: the newest working day still
            // without one, else today if it hasn't one — none once it has.
            'newSheetDate' => $missingDays[0] ?? (in_array($today, $sheetDates, true) ? null : $today),
            'today' => $today,
            'since' => Setting::attendanceSince(),
        ]);
    }

    /**
     * Submits a new day's sheet, everyone's attendance on it at once. A day
     * that already has one is refused — that one's edited instead.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin, Senior Operations and HR Manager keep the attendance sheet.');

        $validated = $request->validateWithBag('attendance', [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.Setting::today(),
                'after_or_equal:'.(Setting::attendanceSince() ?? '1970-01-01'),
                Rule::unique('attendance_sheets', 'date'),
            ],
            ...$this->markRules(),
        ], [
            'date.unique' => self::ONE_A_DAY,
            ...$this->markMessages(),
        ]);

        $this->ensureBelong($validated['attendance']);

        // The date is unique in the database too: two submissions for the same
        // day at once, and the second is turned away like any other.
        try {
            $sheet = DB::transaction(function () use ($request, $validated) {
                $sheet = AttendanceSheet::query()->create([
                    'date' => $validated['date'],
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
                $this->saveMarks($sheet, $validated['attendance']);

                return $sheet;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['date' => self::ONE_A_DAY], 'attendance');
        }

        return $this->backToList($sheet, $validated['attendance']);
    }

    /**
     * Corrects a submitted sheet: who was present and who was absent, and
     * why.
     */
    public function update(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin, Senior Operations and HR Manager keep the attendance sheet.');

        $validated = $request->validateWithBag('attendance', $this->markRules(), $this->markMessages());

        $this->ensureBelong($validated['attendance'], $sheet);

        DB::transaction(function () use ($request, $sheet, $validated) {
            $sheet->update(['updated_by' => $request->user()->id]);
            $this->saveMarks($sheet, $validated['attendance']);
        });

        return $this->backToList($sheet, $validated['attendance']);
    }

    /**
     * Everyone's mark, as the popup sends it: attendance[user id][status,
     * reason, note].
     *
     * @return array<string, array<int, mixed>>
     */
    private function markRules(): array
    {
        return [
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', Rule::in([Attendance::PRESENT, Attendance::ABSENT])],
            'attendance.*.reason' => ['nullable', 'required_if:attendance.*.status,'.Attendance::ABSENT, Rule::in(Attendance::REASONS)],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function markMessages(): array
    {
        return [
            'attendance.*.reason.required_if' => 'Say why they were on leave.',
        ];
    }

    /**
     * Refuses marks for anyone who doesn't go on a sheet — only Sourcing, Data
     * Entry and GM Assistant people do, and anyone already on $sheet.
     *
     * @param  array<int|string, array<string, mixed>>  $marks
     */
    private function ensureBelong(array $marks, ?AttendanceSheet $sheet = null): void
    {
        $belongs = AttendanceSheet::people()->pluck('id')
            ->merge($sheet?->attendances()->pluck('user_id') ?? [])
            ->unique()
            ->all();

        foreach (array_keys($marks) as $userId) {
            abort_unless(in_array((int) $userId, $belongs, true), 422, 'Only Sourcing, Data Entry and GM Assistant people go on the sheet.');
        }
    }

    /**
     * Writes everyone's mark onto $sheet.
     *
     * @param  array<int|string, array{status: string, reason?: ?string, note?: ?string}>  $marks
     */
    private function saveMarks(AttendanceSheet $sheet, array $marks): void
    {
        foreach ($marks as $userId => $mark) {
            $present = $mark['status'] === Attendance::PRESENT;

            $sheet->attendances()->updateOrCreate(['user_id' => (int) $userId], [
                'status' => $mark['status'],
                'reason' => $present ? null : $mark['reason'],
                'note' => $present ? null : ($mark['note'] ?? null),
            ]);
        }
    }

    /**
     * Back to the list, with how the day came out.
     *
     * @param  array<int|string, array{status: string}>  $marks
     */
    private function backToList(AttendanceSheet $sheet, array $marks): RedirectResponse
    {
        $absent = collect($marks)->where('status', Attendance::ABSENT)->count();
        $present = count($marks) - $absent;
        $date = $sheet->date->toDateString();
        $day = $date === Setting::today() ? 'today' : $date;

        return redirect()->route('admin.attendance.index')
            ->with('status', "Attendance saved for {$day} — {$present} present, {$absent} on leave.");
    }
}
