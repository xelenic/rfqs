{{--
    The note under a Returns row's subject: who sent the RFQ back, from which
    stage, and why — its latest rejection (Rfq::rejectToStage()/
    rejectPartToStage()).

    Expects: $rfq (with rejectedBy loaded).
--}}
<div class="rfq-list-subnote rfq-list-subnote-returned">
    <i class="bi bi-arrow-counterclockwise"></i>
    Sent back by {{ \App\Models\Rfq::stageLabel($rfq->reject_from_stage) }}{{ $rfq->rejectedBy ? ' ('.$rfq->rejectedBy->name.')' : '' }}: {{ $rfq->reject_reason }}
</div>
