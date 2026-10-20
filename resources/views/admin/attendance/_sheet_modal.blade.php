{{--
    The sheet popup — a new day's, or a submitted one to correct. The buttons
    that open it say which (data-action, data-sheet-id, data-marks); admin.js
    fills it in. After a refused submission it comes back filled with what was
    sent. Only for those who keep the sheets (User::canManageAttendance()) —
    on the attendance list, and on a sheet's own page.

    Expects: $people (AttendanceSheet::people()), $sheetDates, $today,
    $since; optionally $timeOff (Attendance::timeOffTally() for $people:
    each name shows their time off on hover) and $returnTo ("show": back to
    the sheet's page once it's saved, rather than the list).
--}}
@php
    // "Morning off (08:30–12:30)" — the time each half day off takes (Settings → Half Day).
    $halfDayOff = \App\Models\Setting::halfDayOff();
    $halfLabel = fn (?string $half) => isset(\App\Models\Attendance::HALVES[$half])
        ? \App\Models\Attendance::HALVES[$half].' ('.$halfDayOff[$half]['start'].'–'.$halfDayOff[$half]['end'].')'
        : 'Half off';
@endphp
@php
    $failed = $errors->attendance->any();
    $failedSheet = $failed && old('sheet_id') ? \App\Models\AttendanceSheet::query()->find(old('sheet_id')) : null;
@endphp
<div class="modal fade" id="attendanceModal" tabindex="-1" aria-labelledby="attendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" id="attendanceForm" novalidate class="modal-content"
              action="{{ $failedSheet ? route('admin.attendance.update', $failedSheet) : route('admin.attendance.store') }}"
              data-sheet-dates="{{ json_encode($sheetDates) }}">
            @csrf
            <input type="hidden" name="_method" value="PUT" @disabled(! $failedSheet)>
            <input type="hidden" name="sheet_id" value="{{ $failedSheet?->id }}">
            @isset($returnTo)
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
            @endisset

            <div class="modal-header">
                <h5 class="modal-title" id="attendanceModalLabel">{{ $failedSheet ? 'Edit attendance' : 'New attendance sheet' }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex align-items-end flex-wrap gap-3 mb-3">
                    <div>
                        <label for="attendance-date" class="form-label">Day</label>
                        <input type="date" name="date" id="attendance-date"
                               class="form-control @error('date', 'attendance') is-invalid @enderror"
                               value="{{ $failed ? old('date') : $today }}" data-today="{{ $today }}"
                               @if ($since) min="{{ $since }}" @endif max="{{ $today }}" @readonly($failedSheet)>
                        @error('date', 'attendance')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="invalid-feedback" id="attendance-date-taken">There's already a sheet for this day — only one per day. Edit it from the list.</div>
                    </div>
                    <p class="text-muted-soft small mb-2">Everyone starts present — mark anyone who was off as Leave, or Half day with the half they were off (or a custom time, up to 4 working hours). It goes to HR Executive for approval; once approved, nobody's time counts while they were off.</p>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0 attendance-table">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Role</th>
                                <th>Attendance</th>
                                <th>If off</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($people as $person)
                                @php
                                    $field = fn (string $name) => "attendance.{$person->id}.{$name}";
                                    $status = $failed ? old($field('status'), 'present') : 'present';
                                @endphp
                                <tr data-attendance-user="{{ $person->id }}" @class(['is-absent' => $status === 'absent', 'is-half-day' => $status === 'half_day'])>
                                    <td class="fw-semibold">
                                        {{-- Their time off on hover (_time_off_cards). --}}
                                        @isset($timeOff[$person->id])
                                            <span class="time-off-name" tabindex="0" role="button" data-hover-card="time-off-{{ $person->id }}">{{ $person->name }}</span>
                                        @else
                                            {{ $person->name }}
                                        @endisset
                                    </td>
                                    <td>
                                        @foreach ($person->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) as $roleName)
                                            <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group" aria-label="{{ $person->name }}'s attendance">
                                            @foreach (['present' => ['Present', 'btn-outline-success', 'bi-check2'], 'half_day' => ['Half day', 'btn-outline-orange', 'bi-circle-half'], 'absent' => ['Leave', 'btn-outline-danger', 'bi-x-lg']] as $value => [$label, $class, $icon])
                                                <input type="radio" class="btn-check js-attendance-status" name="attendance[{{ $person->id }}][status]"
                                                       id="attendance-{{ $person->id }}-{{ $value }}" value="{{ $value }}" autocomplete="off" @checked($status === $value)>
                                                <label class="btn {{ $class }}" for="attendance-{{ $person->id }}-{{ $value }}"><i class="bi {{ $icon }}"></i> {{ $label }}</label>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @php $customOff = $status === 'half_day' && $failed && old($field('half_off')) === \App\Models\Attendance::CUSTOM_HALF; @endphp
                                        <div class="d-flex flex-wrap gap-2 attendance-absence">
                                            <select name="attendance[{{ $person->id }}][half_off]" class="form-select form-select-sm w-auto attendance-half @error($field('half_off'), 'attendance') is-invalid @enderror @if ($status !== 'half_day') d-none @endif"
                                                    aria-label="Which half {{ $person->name }} was off">
                                                <option value="">Which half…</option>
                                                @foreach (array_keys(\App\Models\Attendance::HALVES) as $half)
                                                    <option value="{{ $half }}" @selected($failed && old($field('half_off')) === $half)>{{ $halfLabel($half) }}</option>
                                                @endforeach
                                                <option value="{{ \App\Models\Attendance::CUSTOM_HALF }}" @selected($failed && old($field('half_off')) === \App\Models\Attendance::CUSTOM_HALF)>Custom time (up to 4 hours)…</option>
                                            </select>
                                            {{-- A custom time off: the end fills in 4 hours after the start (admin.js). --}}
                                            <div class="d-flex align-items-center gap-1 attendance-custom-off @unless ($customOff) d-none @endunless">
                                                <input type="time" name="attendance[{{ $person->id }}][half_off_start]" class="form-control form-control-sm attendance-off-start"
                                                       value="{{ $failed ? old($field('half_off_start')) : '' }}" aria-label="When {{ $person->name }}'s time off starts">
                                                <span class="text-muted-soft small">to</span>
                                                <input type="time" name="attendance[{{ $person->id }}][half_off_end]" class="form-control form-control-sm attendance-off-end"
                                                       value="{{ $failed ? old($field('half_off_end')) : '' }}" aria-label="When {{ $person->name }}'s time off ends">
                                            </div>
                                            <input type="text" name="attendance[{{ $person->id }}][note]" class="form-control form-control-sm attendance-note"
                                                   value="{{ $failed ? old($field('note')) : '' }}" maxlength="500" placeholder="Note (optional)" aria-label="Note on {{ $person->name }}'s leave">
                                        </div>
                                        @error($field('half_off'), 'attendance')
                                            <div class="text-danger small attendance-error">{{ $message }}</div>
                                        @enderror
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted-soft py-4">Nobody holds the Sourcing, Data Entry or GM Assistant role yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="attendance-submit" @disabled($people->isEmpty())>
                    <i class="bi bi-check2"></i> <span>{{ $failedSheet ? 'Save attendance' : 'Submit attendance' }}</span>
                </button>
            </div>
        </form>
    </div>
</div>

{{-- A refused submission — back in the popup, as it was sent. --}}
@if ($failed)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceModal')).show();
            });
        </script>
    @endpush
@endif
