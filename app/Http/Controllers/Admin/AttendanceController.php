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
 * Assistant person, present, on a half day (the morning or the afternoon off)
 * or on leave, with why when they were off. Kept by
 * Admin and Senior Operations (User::canManageAttendance()); each submitted
 * one goes to HR Manager, who approves it or returns it to be corrected
 * (User::canApproveAttendance()). A person's time only counts on a day whose
 * approved sheet has them present.
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
        abort_unless($request->user()->canViewAttendance(), 403, 'Only Admin, Senior Operations and HR Manager see the attendance sheets.');

        $today = Setting::today();
        $missingDays = AttendanceSheet::missingDays();
        // One sheet a day: every day that has one, so the popup can turn a
        // second away before it's filled in.
        $sheetDates = AttendanceSheet::query()->pluck('date')->map(fn ($date) => $date->toDateString())->all();

        return view('admin.attendance.index', [
            'sheets' => AttendanceSheet::query()
                ->with(['attendances.user', 'createdBy', 'updatedBy', 'approvedBy', 'returnedBy'])
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
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations fill in the attendance sheet.');

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
     * why — and submits it again, for HR Manager to review afresh, even if
     * they'd approved it.
     */
    public function update(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations fill in the attendance sheet.');

        $validated = $request->validateWithBag('attendance', $this->markRules(), $this->markMessages());

        $this->ensureBelong($validated['attendance'], $sheet);

        DB::transaction(function () use ($request, $sheet, $validated) {
            $sheet->submitted($request->user());
            $this->saveMarks($sheet, $validated['attendance']);
        });

        return $this->backToList($sheet, $validated['attendance']);
    }

    /**
     * HR Manager approves a submitted sheet: from now its day's attendance
     * counts — present people's time, and none of anyone's on leave.
     */
    public function approve(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canApproveAttendance(), 403, 'Only HR Manager approves the attendance sheet.');
        abort_unless($sheet->isAwaitingApproval(), 422, 'That sheet isn\'t waiting for approval.');

        $sheet->approve($request->user());

        return redirect()->route('admin.attendance.index')
            ->with('status', "Attendance for {$this->dayLabel($sheet)} approved — it counts now.");
    }

    /**
     * HR Manager returns a submitted sheet to Senior Operations, with why, to
     * correct and submit again. It doesn't count meanwhile.
     */
    public function sendBack(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canApproveAttendance(), 403, 'Only HR Manager returns the attendance sheet.');
        abort_unless($sheet->isAwaitingApproval(), 422, 'That sheet isn\'t waiting for approval.');

        $validated = $request->validateWithBag('attendance_return', [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say what needs correcting.',
        ]);

        $sheet->sendBack($request->user(), $validated['reason']);

        return redirect()->route('admin.attendance.index')
            ->with('status', "Attendance for {$this->dayLabel($sheet)} returned to Senior Operations.");
    }

    /**
     * Everyone's mark, as the popup sends it: attendance[user id][status,
     * half_off, reason, note] — which half they were off on a half day, and
     * why for a half day or a day on leave.
     *
     * @return array<string, array<int, mixed>>
     */
    private function markRules(): array
    {
        return [
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', Rule::in([Attendance::PRESENT, Attendance::HALF_DAY, Attendance::ABSENT])],
            'attendance.*.half_off' => ['nullable', 'required_if:attendance.*.status,'.Attendance::HALF_DAY, Rule::in(array_keys(Attendance::HALVES))],
            'attendance.*.reason' => ['nullable', 'required_unless:attendance.*.status,'.Attendance::PRESENT, Rule::in(Attendance::REASONS)],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function markMessages(): array
    {
        return [
            'attendance.*.reason.required_unless' => 'Say why they were on leave.',
            'attendance.*.half_off.required_if' => 'Say which half they were off.',
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
     * @param  array<int|string, array{status: string, half_off?: ?string, reason?: ?string, note?: ?string}>  $marks
     */
    private function saveMarks(AttendanceSheet $sheet, array $marks): void
    {
        foreach ($marks as $userId => $mark) {
            $present = $mark['status'] === Attendance::PRESENT;

            $sheet->attendances()->updateOrCreate(['user_id' => (int) $userId], [
                'status' => $mark['status'],
                'half_off' => $mark['status'] === Attendance::HALF_DAY ? $mark['half_off'] : null,
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
        $halfDays = collect($marks)->where('status', Attendance::HALF_DAY)->count();
        $present = count($marks) - $absent - $halfDays;
        $halfDayNote = $halfDays > 0 ? " {$halfDays} on a half day," : '';

        return redirect()->route('admin.attendance.index')
            ->with('status', "Attendance saved for {$this->dayLabel($sheet)} — {$present} present,{$halfDayNote} {$absent} on leave. Sent to HR Manager for approval.");
    }

    /**
     * The sheet's day, as a message says it: "today", or its date.
     */
    private function dayLabel(AttendanceSheet $sheet): string
    {
        $date = $sheet->date->toDateString();

        return $date === Setting::today() ? 'today' : $date;
    }
}
