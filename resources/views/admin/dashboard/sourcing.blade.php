{{--
    A Sourcing member's dashboard — their own parts of open RFQs: what's on
    their plate, what's with Data Entry, what came back, and what they were
    given most recently. See DashboardController::sourcingOverview().

    Expects: $overview, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @include('admin.dashboard._header', ['subtitle' => "Here's what's on your plate."])

    <div class="row g-3 mb-4">
        @foreach ([
            [
                'label' => 'Assigned to You', 'value' => $overview['assigned'], 'icon' => 'bi-inboxes', 'iconClass' => '',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                'caption' => $overview['done'].' finished by Data Entry',
                'alert' => false,
            ],
            [
                'label' => 'Pending', 'value' => $overview['pending'], 'icon' => 'bi-hourglass-split', 'iconClass' => 'stat-icon-warning',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                'caption' => $overview['urgent'] > 0 ? $overview['urgent'].' urgent' : 'None urgent',
                'alert' => $overview['urgent'] > 0,
            ],
            [
                'label' => 'In Review', 'value' => $overview['inReview'], 'icon' => 'bi-clipboard2-check', 'iconClass' => 'stat-icon-violet',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                'caption' => 'With Data Entry',
                'alert' => false,
            ],
            [
                'label' => 'Returned', 'value' => $overview['returned'], 'icon' => 'bi-arrow-counterclockwise', 'iconClass' => 'stat-icon-danger',
                'url' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']),
                'caption' => $overview['returned'] > 0 ? 'Needs rework' : 'Nothing sent back',
                'alert' => $overview['returned'] > 0,
            ],
        ] as $card)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $card['url'] }}" class="stat-card-link">
                    <div class="stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">{{ $card['label'] }}</div>
                            <div class="stat-value">{{ $card['value'] }}</div>
                            <div class="stat-caption {{ $card['alert'] ? 'text-danger fw-semibold' : '' }}">{{ $card['caption'] }}</div>
                        </div>
                        <div class="stat-icon {{ $card['iconClass'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Recently assigned</span>
                    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending']) }}" class="small fw-semibold">View all <i class="bi bi-arrow-right"></i></a>
                </div>

                @if ($overview['recent']->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>RFQ</th>
                                    <th>Priority</th>
                                    <th>Assigned</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['recent'] as $part)
                                    @php
                                        $rfq = $part['rfq'];
                                        $assignment = $part['assignment'];
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-nowrap">{{ $part['label'] }}</div>
                                            <div class="text-muted-soft small">{{ $rfq->subject }}</div>
                                        </td>
                                        <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                        <td class="text-muted-soft">
                                            {{ $assignment->created_at?->diffForHumans() ?? '—' }}
                                            <div class="small">{{ $assignment->created_at?->format('M d, g:i A') }}</div>
                                        </td>
                                        <td><span class="badge {{ $assignment->progressBadgeClass() }}">{{ $assignment->progressLabel() }}</span></td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            @if ($assignment->completed_at === null)
                                                @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $part['part'], 'returnTo' => 'dashboard'])
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card-body text-center py-4">
                        <p class="text-muted-soft mb-0">Nothing has been assigned to you yet.</p>
                    </div>
                @endif
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Your workload</span>
                    <span class="text-muted-soft small fw-normal">{{ $overview['assigned'] }} {{ $overview['assigned'] === 1 ? 'part' : 'parts' }} on open RFQs</span>
                </div>
                <div class="card-body">
                    @php
                        $workload = [
                            'in_progress' => $overview['inProgress'],
                            'returned' => $overview['returned'],
                            'with_data_entry' => $overview['inReview'],
                            'data_entry_done' => $overview['done'],
                        ];
                    @endphp

                    @if ($overview['assigned'] > 0)
                        <div class="workload-bar" role="img"
                             aria-label="{{ collect($workload)->map(fn ($count, $state) => $count.' '.strtolower(\App\Models\RfqAssignment::PROGRESS_LABELS[$state]))->implode(', ') }}">
                            @foreach ($workload as $state => $count)
                                @if ($count > 0)
                                    <span class="workload-seg" data-state="{{ $state }}" style="flex: {{ $count }}"
                                          title="{{ \App\Models\RfqAssignment::PROGRESS_LABELS[$state] }}: {{ $count }}"></span>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <ul class="workload-legend list-unstyled mb-0">
                        @foreach ($workload as $state => $count)
                            <li>
                                <span class="workload-dot" data-state="{{ $state }}"></span>
                                <span>{{ \App\Models\RfqAssignment::PROGRESS_LABELS[$state] }}</span>
                                <strong>{{ $count }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Review items</span>
                    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="small fw-semibold">Returns <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    <div class="dash-group-title tone-danger">
                        <i class="bi bi-arrow-counterclockwise"></i> Returned for rework
                        <span class="dash-group-count">{{ $overview['returned'] }}</span>
                    </div>
                    @forelse ($overview['returnedParts'] as $part)
                        <div class="dash-item">
                            <div>
                                <a href="{{ route('admin.rfqs.show', $part['rfq']) }}?status=Pending" class="fw-semibold">{{ $part['label'] }}</a>
                                <div class="small">{{ $part['rfq']->subject }}</div>
                                @if ($part['assignment']->return_reason)
                                    <div class="rfq-list-subnote rfq-list-subnote-returned">
                                        <i class="bi bi-arrow-counterclockwise"></i> {{ $part['assignment']->return_reason }}
                                    </div>
                                @endif
                                <div class="text-muted-soft small">Returned {{ $part['assignment']->returned_at?->diffForHumans() ?? '' }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing has been sent back.</p>
                    @endforelse

                    <div class="dash-group-title tone-violet mt-4">
                        <i class="bi bi-clipboard2-check"></i> With Data Entry
                        <span class="dash-group-count">{{ $overview['inReview'] }}</span>
                    </div>
                    @forelse ($overview['inReviewParts'] as $part)
                        <div class="dash-item">
                            <div>
                                <a href="{{ route('admin.rfqs.show', $part['rfq']) }}?status=Pending" class="fw-semibold">{{ $part['label'] }}</a>
                                <div class="small">{{ $part['rfq']->subject }}</div>
                                <div class="text-muted-soft small">You completed it {{ $part['assignment']->completed_at?->diffForHumans() ?? '' }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing is waiting on Data Entry.</p>
                    @endforelse
                </div>
            </div>

            @include('admin.dashboard._notifications')
        </div>
    </div>

    @include('admin.rfqs._complete_modal')
@endsection
