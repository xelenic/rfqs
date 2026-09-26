{{--
    A button that asks for a comment before it does anything: Mark Complete —
    Sourcing on their own part, or (kind "data_entry") Data Entry on a Sourcing
    part — or (kind "return") Data Entry sending a part back to Sourcing, where
    the comment is the reason. It doesn't act itself: it opens the prompt
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

    For Admin, a Sourcing Mark Complete names the part's assigned member —
    the prompt shows them as who it's recorded as done by. See
    RfqController::completeSourcing().
--}}
@php
    $kind = $kind ?? 'sourcing';
    $isSplit = $rfq->isSplit();
    $name = $who ?? 'them';

    // Admin has no part of their own: completing one is done as its assignee.
    $forAssignee = $kind === 'sourcing' && auth()->user()->hasRole('Admin') ? $rfq->assigneeForPart((int) $part) : null;

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
        'data_entry' => [
            route('admin.rfqs.complete-data-entry', $rfq),
            'Senior Operations',
            'Leave a comment for Senior Operations',
            $isSplit
                ? 'Posted to the RFQ\'s comments. The RFQ only moves on to Senior Operations\' review once Data Entry has completed every part.'
                : 'Posted to the RFQ\'s comments as it moves on to Senior Operations\' review.',
            'What did you check or enter, and is there anything Senior Operations should know?',
            'Mark Complete',
            'btn btn-sm btn-success',
            'bi-check2-circle',
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
            'Mark Complete',
            'btn btn-sm btn-success',
            'bi-check2-circle',
        ],
    };
@endphp
<button type="button" class="{{ $class ?? $defaultClass }} js-complete"
        data-bs-toggle="modal" data-bs-target="#completeModal"
        data-kind="{{ $kind }}"
        data-actor-role="{{ $kind === 'sourcing' ? ($forAssignee ? 'Sourcing' : '') : 'Data Entry' }}"
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
        data-redirect-status="{{ $kind === 'sourcing' && ! ($returnTo ?? null) ? 'Pending' : '' }}"
        data-redirect-view="{{ $kind === 'sourcing' ? ($redirectView ?? '') : '' }}"
        data-redirect-role="{{ $redirectRole ?? '' }}"
        data-back-modal="{{ $backModal ?? '' }}">
    <i class="bi {{ $icon }}"></i> {{ $label ?? $defaultLabel }}
</button>
