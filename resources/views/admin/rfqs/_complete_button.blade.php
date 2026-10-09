{{--
    A button that asks for a comment before it does anything: Mark Complete —
    Sourcing on their own part — or (kind "data_entry") Data Entry's Send to
    Finalize on a Sourcing part — or (kind "return") Data Entry sending a part back to Sourcing, where
    the comment is the reason — or (kind "return_data_entry") the Sourcing
    member sending a part Data Entry sent to finalize back to Data Entry
    instead, again with a reason — or (kind "sourcing_to_ops") the Sourcing
    member sending their own part, still with them, back to Senior Operations
    to assign again, with a reason (RfqController::returnSeniorOps()). It doesn't act
    itself: it opens the prompt
    (_complete_modal.blade.php), pointing it at the right route and part and at
    where to come back to.

    Expects: $rfq, $part (the part number).
    Optional: $kind ('sourcing' by default, 'data_entry' or 'return'), $who
    (whose part it is — Data Entry's), $returnTo ('show' or 'dashboard' —
    otherwise Sourcing's goes back to the Pending list and Data Entry's to
    wherever it was), $redirectView ('returns' — Sourcing reworking a returned
    part from the Returns list goes back there, not to My Pending RFQs),
    $redirectRole (Admin's role page to come back to, e.g. 'sourcing'),
    $backModal (id of the modal this sits in, which Back returns to), $label
    (the button's text), $class (its classes).

    For Admin, a Sourcing Mark Complete or Return to Data Entry names the
    part's assigned member — the prompt shows them as who it's recorded as
    done by. See RfqController::completeSourcing() and returnDataEntry().
--}}
@php
    $kind = $kind ?? 'sourcing';
    $isSplit = $rfq->isSplit();
    $name = $who ?? 'them';

    // The Sourcing member's own actions. Admin has no part of their own:
    // doing one is done as its assignee.
    $isSourcingAction = in_array($kind, ['sourcing', 'return_data_entry', 'sourcing_to_ops'], true);
    $forAssignee = $isSourcingAction && auth()->user()->hasRole('Admin') ? $rfq->assigneeForPart((int) $part) : null;

    [$route, $audience, $heading, $hint, $placeholder, $defaultLabel, $defaultClass, $icon] = match ($kind) {
        'return' => [
            route('admin.rfqs.return-sourcing', $rfq),
            $who ?? 'the Sourcing member',
            'Tell '.$name.' what needs to change',
            'Sent back to '.($who ?? 'Sourcing').' for rework. Your reason is posted to the RFQ\'s comments and shown with the part on their Returns list.',
            'What\'s wrong or missing, so '.$name.' can put it right?',
            'Return to Sourcing',
            'btn btn-sm btn-outline-danger',
            'bi-arrow-counterclockwise',
        ],
        // "Send to Finalize": back to the part's Sourcing member, whose
        // Finalize sends it on to Senior Operations' review.
        'return_data_entry' => [
            route('admin.rfqs.return-data-entry', $rfq),
            'Data Entry',
            'Tell Data Entry what needs to change',
            'Sent back to Data Entry instead of being finalized. Your reason is posted to the RFQ\'s comments and shown with the part on their queue.',
            'What\'s wrong or missing in what Data Entry entered?',
            'Return to Data Entry',
            'btn btn-sm btn-outline-danger',
            'bi-arrow-counterclockwise',
        ],
        // Back to Senior Operations, who frees the part to assign again.
        'sourcing_to_ops' => [
            route('admin.rfqs.return-senior-ops', $rfq),
            'Senior Operations',
            'Tell Senior Operations what\'s wrong',
            'Sent back to Senior Operations to assign again. Your reason is posted to the RFQ\'s comments and shown with it on their Returns page.',
            'Why should Senior Operations look at this part again?',
            'Return to Senior Operations',
            'btn btn-sm btn-outline-danger',
            'bi-arrow-return-left',
        ],
        'data_entry' => [
            route('admin.rfqs.complete-data-entry', $rfq),
            $who ?? 'the Sourcing member',
            'Leave a comment for '.$name,
            'Posted to the RFQ\'s comments. It goes back to '.($who ?? 'the Sourcing member').' to finalize, and on to Senior Operations\' review once they have.',
            'What did you check or enter, and is there anything '.$name.' should know before finalizing?',
            'Send to Finalize',
            'btn btn-sm btn-success',
            'bi-send-check',
        ],
        default => [
            route('admin.rfqs.complete-sourcing', $rfq),
            'Data Entry',
            'Leave a comment for Data Entry',
            $forAssignee
                ? 'Posted to the RFQ\'s comments under '.$forAssignee->name.'\'s name. '.($isSplit ? 'The RFQ only hands off to Data Entry once every part has been completed.' : 'The RFQ hands off to Data Entry.')
                : ($isSplit
                    ? 'Posted to the RFQ\'s comments. The RFQ only hands off to Data Entry once every part has been completed.'
                    : 'Posted to the RFQ\'s comments as you hand it off to Data Entry.'),
            $forAssignee ? 'What was done, and is there anything Data Entry should know?' : 'What did you do, and is there anything Data Entry should know?',
            'Assign to Data Entry',
            'btn btn-sm btn-success',
            'bi-check2-circle',
        ],
    };
@endphp
<button type="button" class="{{ $class ?? $defaultClass }} js-complete"
        data-bs-toggle="modal" data-bs-target="#completeModal"
        data-kind="{{ $kind }}"
        data-actor-role="{{ $isSourcingAction ? ($forAssignee ? 'Sourcing' : '') : 'Data Entry' }}"
        data-assignee-id="{{ $forAssignee?->id }}"
        data-assignee-name="{{ $forAssignee?->name }}"
        data-action="{{ $route }}"
        data-part="{{ $part }}"
        data-rfq-number="{{ $rfq->partNumberLabel($part) }}"
        data-subject="{{ $rfq->subject }}"
        data-who="{{ $who ?? $forAssignee?->name ?? '' }}"
        data-audience="{{ $audience }}"
        data-heading="{{ $heading }}"
        data-hint="{{ $hint }}"
        data-placeholder="{{ $placeholder }}"
        data-return-to="{{ $returnTo ?? '' }}"
        data-redirect-status="{{ $isSourcingAction && ! ($returnTo ?? null) ? 'Pending' : '' }}"
        data-redirect-view="{{ in_array($kind, ['sourcing', 'sourcing_to_ops'], true) ? ($redirectView ?? '') : '' }}"
        data-redirect-role="{{ $redirectRole ?? '' }}"
        data-back-modal="{{ $backModal ?? '' }}">
    <i class="bi {{ $icon }}"></i> {{ $label ?? $defaultLabel }}
</button>
