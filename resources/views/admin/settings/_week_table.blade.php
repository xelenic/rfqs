{{--
    A working week to fill in — the company's (Settings → Working Hours), a
    role's or one person's (WorkingSchedule): one row per day, Sunday first —
    a switch for whether it's worked, its working hours and its lunch, and the
    hours that leaves, kept up to date as it's typed (admin.js,
    .js-week-table). A day switched off keeps its times, greyed out, for when
    it's switched back on. Posts days[<day>][working|start|end|lunch_start|
    lunch_end] — see SettingsController::validatedWeek().

    Expects: $week (Setting::weekFrom()'s shape), $bag (the form's error bag),
    $refill (whether to show what was just sent: only when this form is the
    one that failed — the week forms share their field names), $idPrefix
    (for the inputs' ids); optionally $tableId and $totalId.
--}}
@php
    $bagErrors = $errors->getBag($bag);
    $weekMinutes = collect($week)->sum(fn (array $day) => \App\Models\Setting::workingMinutes($day));
@endphp
<div class="table-responsive">
    <table class="table align-middle mb-0 working-hours-table js-week-table"{!! isset($tableId) ? ' id="'.e($tableId).'"' : '' !!}>
        <thead>
            <tr>
                <th>Day</th>
                <th>Working</th>
                <th>Working hours</th>
                <th>Lunch <span class="text-muted-soft fw-normal text-lowercase">(optional)</span></th>
                <th class="text-end">Hours</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($week as $day => $hours)
                @php
                    $field = fn (string $name) => "days.{$day}.{$name}";
                    $isWorking = $refill ? old($field('working')) === '1' : $hours['working'];
                    $time = fn (string $name) => $refill && $isWorking ? old($field($name)) : $hours[$name];
                    $dayErrors = $refill ? collect(['start', 'end', 'lunch_start', 'lunch_end'])->flatMap(fn (string $name) => $bagErrors->get($field($name))) : collect();
                @endphp
                <tr data-working-day="{{ $day }}" @class(['is-off' => ! $isWorking])>
                    <td class="fw-semibold">{{ ucfirst($day) }}</td>
                    <td>
                        <div class="form-check form-switch mb-0">
                            <input type="hidden" name="days[{{ $day }}][working]" value="0">
                            <input class="form-check-input js-working-day" type="checkbox" role="switch"
                                   name="days[{{ $day }}][working]" value="1" id="{{ $idPrefix }}-{{ $day }}"
                                   aria-label="{{ ucfirst($day) }} is a working day" @checked($isWorking)>
                        </div>
                    </td>
                    <td>
                        <div class="working-hours-range">
                            @foreach (['start' => 'starts', 'end' => 'finishes'] as $name => $label)
                                <input type="time" name="days[{{ $day }}][{{ $name }}]" data-time="{{ $name }}"
                                       class="form-control form-control-sm @if ($refill && $bagErrors->has($field($name))) is-invalid @endif"
                                       value="{{ $time($name) }}" aria-label="{{ ucfirst($day) }} {{ $label }}" @disabled(! $isWorking)>
                                @if ($name === 'start')
                                    <span class="text-muted-soft">to</span>
                                @endif
                            @endforeach
                        </div>
                    </td>
                    <td>
                        <div class="working-hours-range">
                            @foreach (['lunch_start' => 'lunch starts', 'lunch_end' => 'lunch ends'] as $name => $label)
                                <input type="time" name="days[{{ $day }}][{{ $name }}]" data-time="{{ $name }}"
                                       class="form-control form-control-sm @if ($refill && $bagErrors->has($field($name))) is-invalid @endif"
                                       value="{{ $time($name) }}" aria-label="{{ ucfirst($day) }} {{ $label }}" @disabled(! $isWorking)>
                                @if ($name === 'lunch_start')
                                    <span class="text-muted-soft">to</span>
                                @endif
                            @endforeach
                        </div>
                    </td>
                    <td class="text-end text-nowrap js-day-hours">
                        {{ $isWorking ? \App\Models\Setting::hoursLabel(\App\Models\Setting::workingMinutes($hours)) : 'Day off' }}
                    </td>
                </tr>
                @if ($dayErrors->isNotEmpty())
                    <tr class="working-hours-errors">
                        <td colspan="5" class="text-danger small pt-0">
                            @foreach ($dayErrors as $message)
                                <div>{{ $message }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-end text-muted-soft">Working week</td>
                <td class="text-end fw-semibold text-nowrap js-week-total"{!! isset($totalId) ? ' id="'.e($totalId).'"' : '' !!}>{{ \App\Models\Setting::hoursLabel($weekMinutes) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
