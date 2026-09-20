{{--
    One line per part for a stage that's taken part by part — a split RFQ's
    Step Details panel for Senior Operations Approval, Head of Business
    Development, GM Assistant and General Manager: who did it for each part
    and when, or where the part stands. Same look as the Sourcing and Data
    Entry panels beside them.

    Expects: $rfq (with its assignees), $doneColumn (the rfq_user column set
    when the stage is done for a part), $byRelation (the RfqAssignment
    relation for who did it), $reachedColumn (the column of the stage before —
    a part has reached this one once that's set) and $awaiting (what a part
    that's reached it reads until it's done). From the page: $maskIfBlurred,
    $restrictAssignment, $restrictSourcingView.
--}}
<div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
        <thead>
            <tr>
                <th>Assignee</th>
                <th>Split</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rfq->sourcingParts() as $part)
                @php
                    $stepAssignee = $part['assignee'];
                    $stepBlurred = $stepAssignee && ($restrictAssignment || ($restrictSourcingView && $stepAssignee->id !== auth()->id()));
                    $stepDoneAt = $stepAssignee?->pivot->{$doneColumn};
                    $stepReached = $stepAssignee && $stepAssignee->pivot->{$reachedColumn} !== null;
                @endphp
                <tr>
                    @if ($stepAssignee)
                        <td>{{ $maskIfBlurred($stepAssignee->name, $stepBlurred) }}</td>
                        <td>{{ $stepBlurred ? 'Restricted' : $part['number'] }}</td>
                        <td>
                            @if ($stepDoneAt)
                                <span class="badge bg-success-subtle text-success-emphasis">{{ $maskIfBlurred($stepAssignee->pivot->{$byRelation}?->name, $restrictSourcingView) }} · {{ $stepDoneAt->format('M d, Y g:i A') }}</span>
                            @elseif ($stepReached)
                                <span class="badge bg-primary-subtle text-primary-emphasis">{{ $awaiting }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Not yet reached</span>
                            @endif
                        </td>
                    @else
                        <td class="text-muted-soft">Unassigned</td>
                        <td>{{ $part['number'] }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary-emphasis">Not yet reached</span></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
