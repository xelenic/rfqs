{{--
    The Time Spent report, for Admin and Senior Operations (see
    TimeSpentReportController): the average time each tracked role spends on
    an RFQ, then — one tab per lens: Pending, Deadline exceeded, Out of working
    hours, Closed, All — every RFQ some role has started on, with each role's
    time on it and how it stands against Sourcing's deadline. Each time is its
    working time — only the working hours set in Settings count — with the
    elapsed (clock) time under it. Work done out of hours (RfqStep::
    isOutOfHoursWork()) is highlighted, with how much of it fell outside them.
    A role still on an RFQ counts up to now.

    Expects: $rfqs (paginated), $times (Rfq::timeSpent() per RFQ id),
    $deadlines (Rfq::sourcingDeadline() per RFQ id), $averages (per role, in
    seconds: average, elapsed, rfqs, seconds), $outOfHours (seconds,
    stretches, rfqs), $tabs (key => label, count), $tab, $roles, $search.
    From the day attendance starts, a person's time only counts on a day the
    attendance sheet has them present — what's waiting on a sheet, or was a
    day absent, is noted under it (_attendance_note).
--}}
@extends('layouts.app')

@section('title', 'Time Spent')

@section('content')
    <div class="row g-3 mb-4">
        @foreach ([
            'Sourcing' => ['icon' => 'bi-people', 'iconClass' => ''],
            'Data Entry' => ['icon' => 'bi-keyboard', 'iconClass' => 'stat-icon-violet'],
            'GM Assistant' => ['icon' => 'bi-file-earmark-text', 'iconClass' => 'stat-icon-warning'],
        ] as $role => $card)
            <div class="col-md-6 col-xl-3">
                <div class="stat-card d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">{{ $role }} · average per RFQ</div>
                        <div class="stat-value">{{ \App\Models\Setting::durationLabel($averages[$role]['average']) }}</div>
                        <div class="stat-caption">
                            {{ \App\Models\Setting::elapsedLabel($averages[$role]['elapsed']) }} elapsed
                            · {{ $averages[$role]['rfqs'] }} {{ \Illuminate\Support\Str::plural('RFQ', $averages[$role]['rfqs']) }}
                        </div>
                    </div>
                    <div class="stat-icon {{ $card['iconClass'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                </div>
            </div>
        @endforeach

        {{-- Work done out of hours, across every RFQ the search leaves — a
             link to their tab. --}}
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('admin.reports.time-spent', array_filter(['tab' => 'out-of-hours', 'search' => $search !== '' ? $search : null])) }}" class="stat-card-link">
                <div class="stat-card d-flex align-items-center justify-content-between {{ $outOfHours['stretches'] > 0 ? 'stat-card-out-of-hours' : '' }}">
                    <div>
                        <div class="stat-label">Out of working hours</div>
                        <div class="stat-value">{{ \App\Models\Setting::elapsedLabel($outOfHours['seconds']) }}</div>
                        <div class="stat-caption {{ $outOfHours['stretches'] > 0 ? 'text-warning-emphasis fw-semibold' : '' }}">
                            @if ($outOfHours['stretches'] > 0)
                                {{ $outOfHours['stretches'] }} {{ \Illuminate\Support\Str::plural('step', $outOfHours['stretches']) }}
                                · {{ $outOfHours['rfqs'] }} {{ \Illuminate\Support\Str::plural('RFQ', $outOfHours['rfqs']) }}
                            @else
                                All done within working hours
                            @endif
                        </div>
                    </div>
                    <div class="stat-icon stat-icon-danger"><i class="bi bi-moon-stars"></i></div>
                </div>
            </a>
        </div>
    </div>

    {{-- One tab per lens, each with how many RFQs it holds; the search
         narrows them all. --}}
    <ul class="nav nav-tabs time-spent-tabs mb-3" aria-label="Time Spent">
        @foreach ($tabs as $key => $tabInfo)
            <li class="nav-item">
                <a href="{{ route('admin.reports.time-spent', array_filter(['tab' => $key, 'search' => $search !== '' ? $search : null])) }}"
                   @class(['nav-link', 'active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                    {{ $tabInfo['label'] }}
                    <span @class([
                        'badge',
                        'badge-soft-danger' => $key === 'exceeded' && $tabInfo['count'] > 0,
                        'badge-soft-warning' => $key === 'out-of-hours' && $tabInfo['count'] > 0,
                        'badge-soft-secondary' => ! in_array($key, ['exceeded', 'out-of-hours'], true) || $tabInfo['count'] === 0,
                    ])>{{ $tabInfo['count'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search WC number, RFQ number, subject..." style="min-width:260px;">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </form>
            <span class="text-muted-soft small">
                <span class="fw-semibold text-body">Working time</span>, within the
                @if (auth()->user()->hasRole('Admin'))
                    <a href="{{ route('admin.settings.edit', ['tab' => 'working-hours']) }}">working hours</a>
                @else
                    working hours
                @endif
                · <span title="Clock time, nights and days off included">elapsed</span> under it
                · <span class="time-out-of-hours-key"><i class="bi bi-moon-stars"></i> out of hours</span>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>RFQ Number</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th title="Sourcing's time against its priority's target — see Settings → Sourcing Targets">Deadline</th>
                        @foreach ($roles as $role)
                            <th class="text-end">{{ $role }}</th>
                        @endforeach
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rfqs as $rfq)
                        @php $time = $times[$rfq->id]; @endphp
                        <tr>
                            <td class="fw-semibold text-nowrap">
                                <a href="{{ route('admin.rfqs.show', $rfq) }}">{{ $rfq->rfq_number }}</a>
                            </td>
                            <td>{{ $rfq->subject }}</td>
                            <td><span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span></td>
                            <td class="text-nowrap">
                                @include('admin.reports._deadline', ['deadline' => $deadlines[$rfq->id]])
                            </td>
                            @foreach ($roles as $role)
                                @php $roleTime = $time['roles'][$role]; @endphp
                                <td @class(['text-end', 'text-nowrap', 'time-out-of-hours' => $roleTime['out_of_hours_count'] > 0])>
                                    @if ($roleTime['rounds'] === 0)
                                        <span class="text-muted-soft">—</span>
                                    @else
                                        {{ \App\Models\Setting::durationLabel($roleTime['seconds']) }}
                                        @if ($roleTime['ongoing'])
                                            <i class="bi bi-hourglass-split text-warning" title="Still with {{ $role }} — counted up to now"></i>
                                        @endif
                                        <div class="text-muted-soft small" title="Clock time, nights and days off included">{{ \App\Models\Setting::elapsedLabel($roleTime['elapsed']) }} elapsed</div>
                                        @include('admin.reports._attendance_note', ['time' => $roleTime])
                                        @if ($roleTime['out_of_hours_count'] > 0)
                                            <div class="small fw-semibold time-out-of-hours-note" title="{{ $roleTime['out_of_hours_count'] }} {{ \Illuminate\Support\Str::plural('step', $roleTime['out_of_hours_count']) }} finished outside working hours">
                                                <i class="bi bi-moon-stars"></i> {{ \App\Models\Setting::elapsedLabel($roleTime['out_of_hours']) }} out of hours
                                            </div>
                                        @endif
                                        @if ($roleTime['reworks'] > 0)
                                            <div class="text-muted-soft small">{{ $roleTime['reworks'] }} {{ \Illuminate\Support\Str::plural('rework', $roleTime['reworks']) }}</div>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                            <td @class(['text-end', 'text-nowrap', 'time-out-of-hours' => $time['out_of_hours_count'] > 0])>
                                <span class="fw-semibold">{{ \App\Models\Setting::durationLabel($time['seconds']) }}</span>
                                <div class="text-muted-soft small" title="Clock time, nights and days off included">{{ \App\Models\Setting::elapsedLabel($time['elapsed']) }} elapsed</div>
                                @include('admin.reports._attendance_note', ['time' => $time])
                                @if ($time['out_of_hours_count'] > 0)
                                    <div class="small fw-semibold time-out-of-hours-note">
                                        <i class="bi bi-moon-stars"></i> {{ \App\Models\Setting::elapsedLabel($time['out_of_hours']) }} out of hours
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($roles) + 5 }}" class="text-center text-muted-soft py-4">
                                @if ($search !== '')
                                    No RFQs match.
                                @else
                                    {{ match ($tab) {
                                        'pending' => 'No pending RFQs have had time spent on them yet.',
                                        'exceeded' => 'No RFQ has gone past its deadline.',
                                        'out-of-hours' => 'No work has been done out of working hours.',
                                        'closed' => 'No closed RFQs have had time spent on them.',
                                        default => 'No time has been spent on an RFQ yet.',
                                    } }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rfqs->hasPages())
            <div class="card-footer bg-white">
                {{ $rfqs->links() }}
            </div>
        @endif
    </div>
@endsection
