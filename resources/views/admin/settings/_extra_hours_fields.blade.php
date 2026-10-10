{{--
    The fields for one range of someone's extra hours (ExtraWorkingHour) —
    the hours, the day they count from, the weekdays — in a Bootstrap row.
    Shared by Settings → Extra Hours and the Users page's Extra hours
    window. See SettingsController::storeExtraHours().

    Expects: $idPrefix (unique on the page), $showOld (this is the form that
    just failed — its input and errors come back).
--}}
@php
    $pickedDays = $showOld ? (array) old('days', []) : ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
@endphp
<div class="col-md-5">
    <label for="{{ $idPrefix }}-start" class="form-label">Hours</label>
    <div class="working-hours-range">
        <input type="time" name="start" id="{{ $idPrefix }}-start" class="form-control @if ($showOld && $errors->extra_hours->has('start')) is-invalid @endif"
               value="{{ $showOld ? old('start') : '19:00' }}" aria-label="Starts" required>
        <span class="text-muted-soft">to</span>
        <input type="time" name="end" id="{{ $idPrefix }}-end" class="form-control @if ($showOld && $errors->extra_hours->has('end')) is-invalid @endif"
               value="{{ $showOld ? old('end') : '20:00' }}" aria-label="Ends" required>
    </div>
    @foreach (['start', 'end'] as $timeField)
        @error($timeField, 'extra_hours')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    @endforeach
</div>
<div class="col-md-3">
    <label for="{{ $idPrefix }}-from" class="form-label">Counting from</label>
    <input type="date" name="starts_on" id="{{ $idPrefix }}-from" class="form-control @if ($showOld && $errors->extra_hours->has('starts_on')) is-invalid @endif"
           value="{{ $showOld ? old('starts_on') : \App\Models\Setting::today() }}" required>
    @if ($showOld)
        @error('starts_on', 'extra_hours')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    @endif
</div>
<div class="col-12">
    <span class="form-label d-block mb-2">Days</span>
    <div class="d-flex flex-wrap gap-2" role="group" aria-label="Days">
        @foreach (\App\Models\Setting::WEEKDAYS as $weekday)
            <input type="checkbox" class="btn-check" name="days[]" value="{{ $weekday }}" id="{{ $idPrefix }}-day-{{ $weekday }}" autocomplete="off" @checked(in_array($weekday, $pickedDays, true))>
            <label class="btn btn-sm btn-outline-primary" for="{{ $idPrefix }}-day-{{ $weekday }}">{{ ucfirst(substr($weekday, 0, 3)) }}</label>
        @endforeach
    </div>
    @if ($showOld)
        @error('days', 'extra_hours')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    @endif
</div>
