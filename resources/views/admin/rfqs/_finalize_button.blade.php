{{--
    The Sourcing member's Finalize on a part Data Entry has sent to finalize
    (Send to Finalize) — sends that part on to Senior Operations' review. Only
    for its own assignee, or Admin on their behalf; see
    RfqController::finalize() and Rfq::finalizePart().

    Expects: $rfq, $part (the part number).
    Optional: $returnTo ('show' or 'dashboard' — otherwise back to the
    Pending list), $redirectRole (Admin's role page to come back to, e.g.
    'sourcing'), $label (the button's text).
--}}
<form action="{{ route('admin.rfqs.finalize', $rfq) }}" method="POST" class="d-inline"
      data-confirm="Finalize {{ $rfq->partNumberLabel($part) }}? It goes on to Senior Operations' review.">
    @csrf
    @method('PATCH')
    <input type="hidden" name="part" value="{{ $part }}">
    @if ($returnTo ?? null)
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
    @else
        <input type="hidden" name="redirect_status" value="Pending">
        @if ($redirectRole ?? null)
            <input type="hidden" name="redirect_role" value="{{ $redirectRole }}">
        @endif
    @endif
    <button type="submit" class="btn btn-sm btn-success">
        <i class="bi bi-check2-all"></i> {{ $label ?? 'Finalize' }}
    </button>
</form>
