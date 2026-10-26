{{--
    The line under a part on a Rejected page: which review rejected it back,
    and why (the part's own record, RfqAssignment part_rejected_*). Names the
    review, not the person — as the returns do.

    Expects: $assignment (the part's RfqAssignment pivot).
--}}
<div class="rfq-list-subnote rfq-list-subnote-returned" data-rejected-note>
    <i class="bi bi-x-octagon"></i>
    Rejected by {{ \App\Models\Rfq::stageLabel($assignment->part_rejected_from) }}@if ($assignment->part_reject_reason): {{ $assignment->part_reject_reason }}@endif
</div>
