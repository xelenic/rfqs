{{--
    The line under a part on a Rejected page: which review rejected it back,
    and why (the part's own record, RfqAssignment part_rejected_*) — or, on a
    Returns page, which review Returned it (part_reject_is_return). Names the
    review, not the person — as the returns do.

    Expects: $assignment (the part's RfqAssignment pivot).
--}}
<div class="rfq-list-subnote rfq-list-subnote-returned" data-rejected-note>
    <i class="bi {{ $assignment->part_reject_is_return ? 'bi-arrow-return-left' : 'bi-x-octagon' }}"></i>
    {{ $assignment->part_reject_is_return ? 'Returned' : 'Rejected' }} by {{ \App\Models\Rfq::stageLabel($assignment->part_rejected_from) }}@if ($assignment->part_reject_reason): {{ $assignment->part_reject_reason }}@endif
</div>
