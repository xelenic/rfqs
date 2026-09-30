{{--
    What a Sourcing member can do with a part Data Entry has sent to
    finalize: Return to Data Entry (with a reason, in the shared prompt —
    _complete_modal.blade.php must be on the page) or Finalize, which sends
    it on to Senior Operations' review. See RfqController::returnDataEntry()
    and finalize().

    Expects: $rfq, $part (the part number).
    Optional: $returnTo ('show' or 'dashboard'), $redirectRole (Admin's role
    page to come back to, e.g. 'sourcing'), $backModal (id of the modal these
    sit in, which the prompt's Back returns to), $finalizeLabel, $returnLabel.
--}}
<div class="d-inline-flex gap-2">
    @include('admin.rfqs._complete_button', [
        'rfq' => $rfq,
        'part' => $part,
        'kind' => 'return_data_entry',
        'returnTo' => $returnTo ?? null,
        'redirectRole' => $redirectRole ?? null,
        'backModal' => $backModal ?? null,
        'label' => $returnLabel ?? null,
    ])
    @include('admin.rfqs._finalize_button', [
        'rfq' => $rfq,
        'part' => $part,
        'returnTo' => $returnTo ?? null,
        'redirectRole' => $redirectRole ?? null,
        'label' => $finalizeLabel ?? null,
    ])
</div>
