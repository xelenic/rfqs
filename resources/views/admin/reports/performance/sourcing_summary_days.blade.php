{{--
    The Sourcing Summary, day by day — one line for each day anyone's figures
    were typed in (SourcingDayFigure), latest first: the calls and expected RFQs
    added up, and the RFQs received from Sourcing that day — those finalized,
    each once. View opens that day's
    sheet (sourcing_summary). Senior Operations and Admin can delete a day's
    typed-in figures; what's worked out from the RFQs stays. Any day can be opened,
    which is also how they add one. See
    Admin\PerformanceReportController::sourcingSummaryDays().

    Expects: $days (paginated: day, people, the figures added up), $received
    (RFQs received, by day), $expected (expected RFQs, by day — typed in or
    each person's usual), $canEditFigures.
--}}
@extends('layouts.app')

@section('title', 'Sourcing Summary')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $typedIn = fn (mixed $figure) => $figure === null ? '—' : number_format((int) $figure);
    @endphp

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Day by day</span>
            <form method="GET" action="{{ route('admin.reports.sourcing-summary.sheet') }}" class="d-flex align-items-center gap-2 flex-wrap report-save">
                <label for="open-day" class="small text-muted-soft mb-0">{{ $canEditFigures ? 'Add or open a day' : 'Open a day' }}</label>
                <input type="date" id="open-day" name="from" value="{{ \App\Models\Setting::today() }}" max="{{ \App\Models\Setting::today() }}" class="form-control form-control-sm" style="width: auto;"
                       oninput="this.form.elements.to.value = this.value">
                <input type="hidden" name="to" value="{{ \App\Models\Setting::today() }}">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-right"></i> Open</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table" data-summary-days>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th class="text-end">People</th>
                        @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callLabel)
                            <th class="text-end report-typed-in">{{ $callLabel }}</th>
                        @endforeach
                        <th class="text-end report-typed-in">Number of expected RFQ</th>
                        <th class="text-end">Number of Received RFQ</th>
                        <th class="text-end report-save">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($days as $listed)
                        @php $listedDate = \Carbon\CarbonImmutable::parse($listed->day); @endphp
                        <tr data-day="{{ $listed->day }}">
                            <td class="fw-semibold text-nowrap">{{ $listedDate->format('d-m-Y') }} <span class="text-muted-soft small fw-normal">{{ $listedDate->format('D') }}</span></td>
                            <td class="text-end">{{ number_format($listed->people) }}</td>
                            @foreach (array_keys(\App\Models\SourcingDayFigure::CALL_FIELDS) as $callField)
                                <td class="text-end report-typed-in">{{ $typedIn($listed->{$callField}) }}</td>
                            @endforeach
                            <td class="text-end report-typed-in">{{ $typedIn($expected->get($listed->day)) }}</td>
                            <td class="text-end">{{ number_format($received->get($listed->day, 0)) }}</td>
                            <td class="text-end text-nowrap report-save">
                                <a href="{{ route('admin.reports.sourcing-summary.sheet', ['from' => $listed->day, 'to' => $listed->day]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a>
                                @if ($canEditFigures)
                                    <form action="{{ route('admin.reports.sourcing-summary.destroy', $listed->day) }}" method="POST" class="d-inline"
                                          data-confirm="Delete the typed-in figures for {{ $listedDate->format('d-m-Y') }}? That's {{ trans_choice('1 person\'s|:count people\'s', $listed->people, ['count' => $listed->people]) }} supplier calls and expected RFQs for the day. What's worked out from the RFQs stays.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted-soft py-4">No days yet — they're listed here once someone in Sourcing saves their calls, or expected RFQs are set.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($days->hasPages())
            <div class="card-footer bg-white">
                {{ $days->links() }}
            </div>
        @endif
    </div>

    @include('admin.reports.performance._how_counted', ['points' => [
        'A day is listed once anyone\'s figures have been typed in for it: a Sourcing member\'s supplier calls, or expected RFQs set by Senior Operations.',
        'Received: the RFQs Senior Operations received from Sourcing that day — the ones finalized, each RFQ once — as on the day\'s sheet.',
        'Yellow figures are added up across the people who entered them. Expected RFQs count each person\'s usual figure (set on their user page) on a working day nobody set one for. A dash means there\'s none.',
        'View opens the day\'s full sheet, worked out from the RFQs. Deleting a day removes only its typed-in figures.',
    ]])
@endsection
