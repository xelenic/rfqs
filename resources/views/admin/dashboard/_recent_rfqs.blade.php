{{-- The latest RFQs, company-wide. Expects: $recentRfqs. --}}
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Recent RFQs</span>
        <a href="{{ route('admin.rfqs.index') }}" class="small fw-semibold">View all <i class="bi bi-arrow-right"></i></a>
    </div>

    @if ($recentRfqs->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>WC Number</th>
                        <th>Subject</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentRfqs as $rfq)
                        <tr>
                            <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                            <td>{{ $rfq->subject }}</td>
                            <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                            <td><span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span></td>
                            <td class="text-muted-soft">{{ $rfq->created_at->diffForHumans() }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.rfqs.show', $rfq) }}" class="btn btn-sm btn-outline-secondary" title="View details">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card-body text-center py-4">
            <p class="text-muted-soft mb-2">No RFQs yet.</p>
            @can('rfqs.create')
                <a href="{{ route('admin.rfqs.index') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> Create the first one
                </a>
            @endcan
        </div>
    @endif
</div>
