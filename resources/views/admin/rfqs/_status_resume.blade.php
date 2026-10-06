{{--
    Sets an RFQ on hold, or cancelled, going again — Resume, or Reopen for a
    cancelled one — just where it was (RfqController::changeStatus()). With
    $stoppedPart, just that part of a split, stopped on its own
    (Rfq::changePartStatus()). For Senior Operations and Admin.

    Expects: $rfq, $stoppedPart (an RfqAssignment stopped on its own, or null
    for the whole RFQ — then it's the RFQ that's stopped, Rfq::isStopped()).
    Always passed: one from the page around it would point this at the wrong
    thing. Optional: $showLabel (the word beside the icon).
--}}
@php
    $isCancelled = ($stoppedPart?->status ?? $rfq->status) === \App\Models\Rfq::CANCELLED;
    $resumeWord = $isCancelled ? 'Reopen' : 'Resume';
    $resumeTarget = $stoppedPart ? $rfq->partNumberLabel($stoppedPart->part_number) : $rfq->rfq_number;
@endphp
<form action="{{ route('admin.rfqs.change-status', $rfq) }}" method="POST" class="d-inline"
      data-confirm="{{ $resumeWord }} {{ $resumeTarget }}? It goes back into progress where it was.">
    @csrf
    @method('PATCH')
    <input type="hidden" name="status" value="Pending">
    @if ($stoppedPart)
        <input type="hidden" name="part" value="{{ $stoppedPart->part_number }}">
    @endif
    <button type="submit" class="btn btn-sm btn-outline-success" title="{{ $resumeWord }} {{ $resumeTarget }}">
        <i class="bi {{ $isCancelled ? 'bi-arrow-repeat' : 'bi-play-circle' }}"></i>
        @if ($showLabel ?? true) {{ $resumeWord }}{{ $stoppedPart ? ' P'.$stoppedPart->part_number : '' }} @endif
    </button>
</form>
