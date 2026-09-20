{{--
    The button that opens the Assign Sourcing wizard (_assign_modal.blade.php)
    — shared by the RFQ list rows and the RFQ detail header. Carries what the
    wizard needs to open in the right mode: the RFQ's number (for the part
    labels), subject (to help pick its category) and category once set, its
    planned split if any, and who already holds which part.

    Expects: $rfq, $restrictAssignment. Optional: $showLabel (text next to the
    icon, for the detail page).
--}}
@php
    $assignedParts = $rfq->sourcingParts()
        ->whereNotNull('assignee')
        ->mapWithKeys(fn (array $part) => [$part['part'] => [
            'id' => $part['assignee']->id,
            'name' => $part['assignee']->name,
        ]]);

    // Once a split's been planned (or anyone assigned), reopening only fills
    // the parts still empty.
    $isFillingRemaining = $rfq->split_count !== null;
    $assignTitle = $isFillingRemaining ? 'Assign the remaining parts' : 'Assign Sourcing';
@endphp
<button type="button" class="btn btn-sm btn-outline-secondary js-assign-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
        {{ $restrictAssignment ? 'disabled' : '' }}
        data-bs-toggle="modal" data-bs-target="#assignRfqModal"
        data-action="{{ route('admin.rfqs.assign', $rfq) }}"
        data-rfq-number="{{ $rfq->rfq_number }}"
        data-rfq-subject="{{ $rfq->subject }}"
        data-category="{{ $rfq->category }}"
        data-split-count="{{ $rfq->split_count }}"
        data-assigned="{{ $assignedParts->toJson() }}"
        title="{{ $restrictAssignment ? 'Restricted for your role' : $assignTitle }}">
    <i class="bi bi-person-plus"></i>@if ($showLabel ?? false) {{ $isFillingRemaining ? 'Assign Remaining' : 'Assign Sourcing' }}@endif
</button>
