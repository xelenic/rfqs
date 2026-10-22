{{--
    Whose RFQ it is: the marketing (Business Development) person who created
    it — their photo and name — or a dash for one with no creator on record.

    Expects: $rfq (with creator).
--}}
@if ($rfq->creator)
    <span class="dash-person">
        <x-avatar :user="$rfq->creator" class="dash-avatar" />
        <span class="text-truncate">{{ $rfq->creator->name }}</span>
    </span>
@else
    <span class="text-muted-soft">—</span>
@endif
