{{--
    HR Executive's Return — what needs correcting — pointed at a sheet by the
    Return button that opens it (admin.js, .js-attendance-return); back open
    against the same sheet if it's sent without a reason. Only for those who
    review the sheets (User::canApproveAttendance()) — on the attendance list,
    and on a sheet's own page.

    Expects: $dayLabel (a "Y-m-d" day as it reads); optionally $returnTo
    ("show": back to the sheet's page afterwards, rather than the list).
--}}
@php
    $failedReturn = $errors->attendance_return->any();
    $failedReturnSheet = $failedReturn && old('return_sheet_id') ? \App\Models\AttendanceSheet::query()->find(old('return_sheet_id')) : null;
@endphp
<div class="modal fade" id="attendanceReturnModal" tabindex="-1" aria-labelledby="attendanceReturnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="attendanceReturnForm" class="modal-content" novalidate
              action="{{ $failedReturnSheet ? route('admin.attendance.return', $failedReturnSheet) : '#' }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="return_sheet_id" value="{{ $failedReturnSheet?->id }}">
            @isset($returnTo)
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
            @endisset

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="attendanceReturnModalLabel">Return to Senior Operations</h5>
                    <div class="text-muted-soft small" id="attendanceReturnDay">{{ $failedReturnSheet ? $dayLabel($failedReturnSheet->date->toDateString()) : '' }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <label for="attendance-return-reason" class="form-label">What needs correcting</label>
                <textarea name="reason" id="attendance-return-reason" rows="3" maxlength="1000"
                          class="form-control @error('reason', 'attendance_return') is-invalid @enderror">{{ $failedReturn ? old('reason') : '' }}</textarea>
                @error('reason', 'attendance_return')
                    <div class="invalid-feedback">{{ $message }}</div>
                @else
                    <div class="form-text">Senior Operations sees this with the sheet. It doesn't count until it's corrected and approved.</div>
                @enderror
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-arrow-counterclockwise"></i> Return
                </button>
            </div>
        </form>
    </div>
</div>

@if ($failedReturnSheet)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceReturnModal')).show();
            });
        </script>
    @endpush
@endif
