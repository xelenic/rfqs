<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Senior Operations' daily attendance sheets (AttendanceSheet), one a day:
 * every day's submitted sheet listed, newest first, and a new one — or a
 * correction to one already submitted — filled in a popup: every Sourcing, Data Entry and GM
 * Assistant person, present, on a half day (the morning or the afternoon off,
 * or a custom time of up to 4 working hours) or on leave, with why when they were off. Kept by
 * Admin and Senior Operations (User::canManageAttendance()); each submitted
 * one goes to HR Executive, who approves it or returns it to be corrected
 * (User::canApproveAttendance()). A person's time only counts on a day whose
 * approved sheet has them present. Each sheet has its own page too (show()).
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
        abort_unless($request->user()->canViewAttendance(), 403, 'Only Admin, Senior Operations and HR Executive see the attendance sheets.');

        $today = Setting::today();
        $missingDays = AttendanceSheet::missingDays();
        // One sheet a day: every day that has one, so the popup can turn a
        // second away before it's filled in.
        $sheetDates = AttendanceSheet::query()->pluck('date')->map(fn ($date) => $date->toDateString())->all();
        $sheets = AttendanceSheet::query()
            ->with(['attendances.user', 'createdBy', 'updatedBy', 'approvedBy', 'returnedBy'])
            ->latest('date')
            ->paginate(self::PER_PAGE);
        $people = AttendanceSheet::people();

        return view('admin.attendance.index', [
            'sheets' => $sheets,
            // The time off of everyone off on these sheets, and of everyone
            // in the sheet popup, for the hover on their name.
            'timeOff' => Attendance::timeOffTally($sheets->getCollection()->flatMap->attendances
                ->whereIn('status', [Attendance::ABSENT, Attendance::HALF_DAY])
                ->pluck('user_id')
                ->merge($people->pluck('id'))),
            'people' => $people,
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
     * One sheet's own page: everyone on it — their role, their mark and any
     * note — who submitted it and last edited it, and where HR Executive's
     * review stands, with the sheets either side of it. Approve, Return and
     * Edit from here come back here.
     */
    public function show(Request $request, AttendanceSheet $sheet): View
    {
        abort_unless($request->user()->canViewAttendance(), 403, 'Only Admin, Senior Operations and HR Executive see the attendance sheets.');

        $sheet->load(['attendances.user.roles', 'createdBy', 'updatedBy', 'approvedBy', 'returnedBy']);
        $date = $sheet->date->toDateString();
        $people = AttendanceSheet::people();

        return view('admin.attendance.show', [
            'sheet' => $sheet,
            'lines' => $sheet->attendances->sortBy(fn (Attendance $line) => $line->user?->name)->values(),
            // Everyone's time off — on the sheet, and in the Edit popup — for
            // the hover on their name.
            'timeOff' => Attendance::timeOffTally($sheet->attendances->pluck('user_id')->merge($people->pluck('id'))),
            'previousSheet' => AttendanceSheet::query()->whereDate('date', '<', $date)->latest('date')->first(['id', 'date']),
            'nextSheet' => AttendanceSheet::query()->whereDate('date', '>', $date)->oldest('date')->first(['id', 'date']),
            // For the Edit popup.
            'people' => $people,
            'sheetDates' => AttendanceSheet::query()->pluck('date')->map(fn ($day) => $day->toDateString())->all(),
            'today' => Setting::today(),
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
        $this->ensureCustomTimeOff($validated['attendance'], $validated['date']);

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

        return $this->backToList($request, $sheet, $validated['attendance']);
    }

    /**
     * Corrects a submitted sheet: who was present and who was absent, and
     * why — and submits it again, for HR Executive to review afresh, even if
     * they'd approved it.
     */
    public function update(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403, 'Only Admin and Senior Operations fill in the attendance sheet.');

        $validated = $request->validateWithBag('attendance', $this->markRules(), $this->markMessages());

        $this->ensureBelong($validated['attendance'], $sheet);
        $this->ensureCustomTimeOff($validated['attendance'], $sheet->date->toDateString());

        DB::transaction(function () use ($request, $sheet, $validated) {
            $sheet->submitted($request->user());
            $this->saveMarks($sheet, $validated['attendance']);
        });

        return $this->backToList($request, $sheet, $validated['attendance']);
    }

    /**
     * HR Executive approves a submitted sheet: from now its day's attendance
     * counts — present people's time, and none of anyone's on leave.
     */
    public function approve(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canApproveAttendance(), 403, 'Only HR Executive approves the attendance sheet.');
        abort_unless($sheet->isAwaitingApproval(), 422, 'That sheet isn\'t waiting for approval.');

        $sheet->approve($request->user());

        return $this->backFrom($request, $sheet)
            ->with('status', "Attendance for {$this->dayLabel($sheet)} approved — it counts now.");
    }

    /**
     * HR Executive returns a submitted sheet to Senior Operations, with why, to
     * correct and submit again. It doesn't count meanwhile.
     */
    public function sendBack(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->canApproveAttendance(), 403, 'Only HR Executive returns the attendance sheet.');
        abort_unless($sheet->isAwaitingApproval(), 422, 'That sheet isn\'t waiting for approval.');

        $validated = $request->validateWithBag('attendance_return', [
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say what needs correcting.',
        ]);

        $sheet->sendBack($request->user(), $validated['reason']);

        return $this->backFrom($request, $sheet)
            ->with('status', "Attendance for {$this->dayLabel($sheet)} returned to Senior Operations.");
    }

    /**
     * Everyone's mark, as the popup sends it: attendance[user id][status,
     * half_off, half_off_start, half_off_end, note] — which half they were
     * off on a half day, or from when to when (Attendance::CUSTOM_HALF), and
     * a note if there's anything to say. Why they were off isn't asked.
     *
     * @return array<string, array<int, mixed>>
     */
    private function markRules(): array
    {
        return [
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', Rule::in([Attendance::PRESENT, Attendance::HALF_DAY, Attendance::ABSENT])],
            'attendance.*.half_off' => ['nullable', 'required_if:attendance.*.status,'.Attendance::HALF_DAY, Rule::in([...array_keys(Attendance::HALVES), Attendance::CUSTOM_HALF])],
            'attendance.*.half_off_start' => ['nullable', 'date_format:H:i'],
            'attendance.*.half_off_end' => ['nullable', 'date_format:H:i'],
            'attendance.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function markMessages(): array
    {
        return [
            'attendance.*.half_off.required_if' => 'Say which half they were off.',
            'attendance.*.half_off_start.date_format' => 'Give the time off as times, like 10:00 to 14:00.',
            'attendance.*.half_off_end.date_format' => 'Give the time off as times, like 10:00 to 14:00.',
        ];
    }

    /**
     * Refuses a half day off a custom time that isn't one on $date: both
     * times, the end after the start, some of the person's working time
     * (Setting::workingSecondsBetween(), in their own hours) and no more than
     * Attendance::MAX_CUSTOM_OFF_SECONDS of it. Said under their half day.
     *
     * @param  array<int|string, array<string, mixed>>  $marks
     *
     * @throws ValidationException
     */
    private function ensureCustomTimeOff(array $marks, string $date): void
    {
        $errors = [];

        foreach ($marks as $userId => $mark) {
            if ($mark['status'] !== Attendance::HALF_DAY || ($mark['half_off'] ?? null) !== Attendance::CUSTOM_HALF) {
                continue;
            }

            $start = $mark['half_off_start'] ?? null;
            $end = $mark['half_off_end'] ?? null;
            $field = "attendance.{$userId}.half_off";

            if ($start === null || $end === null) {
                $errors[$field] = 'Give the time they were off — from and to.';

                continue;
            }

            if ($end <= $start) {
                $errors[$field] = 'The time off has to end after it starts.';

                continue;
            }

            $zone = Setting::timezone();
            $seconds = Setting::workingSecondsBetween(CarbonImmutable::parse("{$date} {$start}", $zone), CarbonImmutable::parse("{$date} {$end}", $zone), (int) $userId);

            if ($seconds === 0) {
                $errors[$field] = "{$start}–{$end} is outside their working hours that day.";
            } elseif ($seconds > Attendance::MAX_CUSTOM_OFF_SECONDS) {
                $errors[$field] = 'A half day is up to '.Setting::durationLabel(Attendance::MAX_CUSTOM_OFF_SECONDS)." of working time — {$start}–{$end} is ".Setting::durationLabel($seconds).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag('attendance');
        }
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
     * Writes everyone's mark onto $sheet. Someone marked present again loses
     * any reason an older sheet gave for their leave.
     *
     * @param  array<int|string, array{status: string, half_off?: ?string, half_off_start?: ?string, half_off_end?: ?string, note?: ?string}>  $marks
     */
    private function saveMarks(AttendanceSheet $sheet, array $marks): void
    {
        foreach ($marks as $userId => $mark) {
            $present = $mark['status'] === Attendance::PRESENT;
            $custom = $mark['status'] === Attendance::HALF_DAY && $mark['half_off'] === Attendance::CUSTOM_HALF;

            $sheet->attendances()->updateOrCreate(['user_id' => (int) $userId], [
                'status' => $mark['status'],
                'half_off' => $mark['status'] === Attendance::HALF_DAY ? $mark['half_off'] : null,
                'half_off_start' => $custom ? $mark['half_off_start'] : null,
                'half_off_end' => $custom ? $mark['half_off_end'] : null,
                'note' => $present ? null : ($mark['note'] ?? null),
            ] + ($present ? ['reason' => null] : []));
        }
    }

    /**
     * Back to where the sheet was saved from (backFrom()), with how the day
     * came out.
     *
     * @param  array<int|string, array{status: string}>  $marks
     */
    private function backToList(Request $request, AttendanceSheet $sheet, array $marks): RedirectResponse
    {
        $absent = collect($marks)->where('status', Attendance::ABSENT)->count();
        $halfDays = collect($marks)->where('status', Attendance::HALF_DAY)->count();
        $present = count($marks) - $absent - $halfDays;
        $halfDayNote = $halfDays > 0 ? " {$halfDays} on a half day," : '';

        return $this->backFrom($request, $sheet)
            ->with('status', "Attendance saved for {$this->dayLabel($sheet)} — {$present} present,{$halfDayNote} {$absent} on leave. Sent to HR Executive for approval.");
    }

    /**
     * Back to where something was done to $sheet from: its own page
     * (return_to "show"), or the list.
     */
    private function backFrom(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        return $request->input('return_to') === 'show'
            ? redirect()->route('admin.attendance.show', $sheet)
            : redirect()->route('admin.attendance.index');
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
