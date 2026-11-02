{{--
    A Sourcing member's Daily Summary: their supplier calls for a day, to add or
    update (_day_form); how that day went for them, worked out from their RFQs;
    and every day they've a summary for, one line each, to view or delete. Deleting
    takes their calls off; what Senior Operations expected of them stays. See
    SourcingDayFigureController::index().

    Expects: $days (their SourcingDayFigures, paginated), $callsDay, $callsFigure,
    $summary (their App\PerformanceReports::sourcingSummary() row for $callsDay).
--}}
@extends('layouts.app')

@section('title', 'Daily Summary')

@section('content')
    @include('admin.sourcing_calls._day_form')

    <div class="card mb-4" data-day-summary>
        <div class="card-header">{{ \Carbon\CarbonImmutable::parse($callsDay)->format('l, M d, Y') }}</div>
        <div class="card-body">
            <dl class="rfq-detail-grid mb-0">
                <div>
                    <dt>RFQ Finalized Successfully</dt>
                    <dd>{{ number_format($summary['finalized']) }} <span class="text-muted-soft small">· {{ number_format($summary['finalized_items']) }} items</span></dd>
                </div>
                <div>
                    <dt>No of Pending RFQ</dt>
                    <dd>{{ number_format($summary['pending']) }} <span class="text-muted-soft small">· {{ number_format($summary['pending_items']) }} items</span></dd>
                </div>
                <div>
                    <dt>RFQ pending for Customer Clarifications</dt>
                    <dd>{{ number_format($summary['customer']) }}</dd>
                </div>
                <div>
                    <dt>Number of Received RFQ</dt>
                    <dd>{{ number_format($summary['received']) }}</dd>
                </div>
                <div>
                    <dt>Number of expected RFQ</dt>
                    <dd>{{ $summary['expected_rfqs'] === null ? '—' : number_format($summary['expected_rfqs']) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Your days</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table" data-sourcing-days>
                <thead>
                    <tr>
                        <th>Day</th>
                        @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callLabel)
                            <th class="text-end">{{ $callLabel }}</th>
                        @endforeach
                        <th class="text-end">Number of expected RFQ</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($days as $listed)
                        @php $listedDate = \Carbon\CarbonImmutable::parse($listed->day); @endphp
                        <tr @class(['table-active' => $listed->day === $callsDay]) data-day="{{ $listed->day }}">
                            <td class="fw-semibold text-nowrap">{{ $listedDate->format('d-m-Y') }} <span class="text-muted-soft small fw-normal">{{ $listedDate->format('D') }}</span></td>
                            @foreach (array_keys(\App\Models\SourcingDayFigure::CALL_FIELDS) as $callField)
                                <td class="text-end">{{ $listed->{$callField} === null ? '—' : number_format($listed->{$callField}) }}</td>
                            @endforeach
                            <td class="text-end">{{ $listed->expected_rfqs === null ? '—' : number_format($listed->expected_rfqs) }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.sourcing-calls.index', ['calls_date' => $listed->day]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> View</a>
                                @if ($listed->hasCalls())
                                    <form action="{{ route('admin.sourcing-calls.destroy', $listed->day) }}" method="POST" class="d-inline"
                                          data-confirm="Delete your supplier calls for {{ $listedDate->format('d-m-Y') }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-soft py-4">No days yet — save your supplier calls above and they'll be listed here.</td>
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
@endsection
