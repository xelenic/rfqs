{{--
    "Send back to …": whoever something was sent back to sends it straight
    back to the reviewer who sent it, skipping the steps in between — their
    earlier work stands (RfqController::forwardBack(), Rfq::forwardBack()).
    Asks first, naming what it skips.

    Expects: $rfq, $returns (open RfqReturns, all from the same stage to the
    same one — Rfq::openReturnFor() / openReturnsTo()), $part (the part
    number, or null for every part in $returns).
--}}
@php
    $first = $returns->first();
    $toWhom = \App\Models\Rfq::stageLabel($first->from_stage);
    $skipped = collect($first->skippedStages())->map(fn (string $stage) => \App\Models\Rfq::stageLabel($stage))->join(', ', ' and ');
    $what = $part ? $rfq->partNumberLabel($part) : $rfq->rfq_number;
@endphp
<form action="{{ route('admin.rfqs.forward-back', $rfq) }}" method="POST" class="d-inline"
      data-confirm="Send {{ $what }} straight back to {{ $toWhom }}{{ $first->returnedBy ? ' ('.$first->returnedBy->name.')' : '' }}? It skips {{ $skipped }} — the work done there before stands.">
    @csrf
    @method('PATCH')
    @if ($part)
        <input type="hidden" name="part" value="{{ $part }}">
    @endif
    <input type="hidden" name="target" value="{{ $first->target_stage }}">
    <input type="hidden" name="from" value="{{ $first->from_stage }}">
    @include('admin.rfqs._acting_as', ['role' => \App\Models\Rfq::RETURN_TARGET_ROLES[$first->target_stage]])
    <button type="submit" class="btn btn-sm btn-outline-primary" title="Skips {{ $skipped }}">
        <i class="bi bi-skip-forward-fill"></i> Send back to {{ $toWhom }}
    </button>
</form>
