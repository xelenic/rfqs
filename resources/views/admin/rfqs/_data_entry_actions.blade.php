{{--
    Data Entry's own buttons on a part with them, by where it is: Start
    before they've begun (_start_data_entry_button); while it's running,
    Pause — asking why (_pause_data_entry_modal, which the page includes);
    its countdown stops, and they're free to start another — and
    Send to Finalize; once paused, Resume, which carries the countdown on
    from where it stopped — off while they've another part running, as Start
    is. Pause and Resume are whoever started it's (Admin's too, for them).
    See RfqController::pauseDataEntry() and resumeDataEntry().

    Expects: $rfq, $part. Optional: $who (the part's Sourcing member, for
    Send to Finalize's wording), $backModal (the quick-detail modal it sits
    in), $myRunningDataEntry (from the controller).
--}}
@php
    $pivot = $rfq->assigneeForPart((int) $part)?->pivot;
    $ownsIt = $pivot && (auth()->user()->hasRole('Admin') || (int) $pivot->data_entry_started_by === auth()->id());
    $runningElsewhere = $myRunningDataEntry ?? null;
@endphp
@if ($pivot?->isDataEntryPaused())
    @if ($ownsIt)
        <form action="{{ route('admin.rfqs.resume-data-entry', $rfq) }}" method="POST" class="d-inline-flex">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="{{ $part }}">
            <span @class(['d-inline-block start-hint', 'is-off' => $runningElsewhere]) tabindex="0"
                  title="{{ $runningElsewhere ? "You're on {$runningElsewhere->rfq->partNumberLabel($runningElsewhere->part_number)} — send it to finalize, or pause it, before resuming this one." : 'Resume — its countdown carries on from where it stopped.' }}">
                <button type="submit" class="btn btn-sm btn-primary" @disabled($runningElsewhere)>
                    <i class="bi bi-play-fill"></i> Resume
                </button>
            </span>
        </form>
    @endif
@elseif ($pivot?->hasDataEntryStarted())
    @if ($ownsIt)
        {{-- Asks why, and for the document it's waiting on (_pause_data_entry_modal). --}}
        <button type="button" class="btn btn-sm btn-outline-secondary js-pause-data-entry" title="Pause — its countdown stops, and you can start another"
                data-bs-toggle="modal" data-bs-target="#pauseDataEntryModal"
                data-action="{{ route('admin.rfqs.pause-data-entry', $rfq) }}"
                data-part="{{ $part }}"
                data-rfq-number="{{ $rfq->partNumberLabel((int) $part) }}"
                data-subject="{{ $rfq->subject }}"
                data-back-modal="{{ $backModal ?? '' }}">
            <i class="bi bi-pause-fill"></i> Pause
        </button>
    @endif
    @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $part, 'kind' => 'data_entry', 'who' => $who ?? null, 'backModal' => $backModal ?? null])
@else
    @include('admin.rfqs._start_data_entry_button', ['rfq' => $rfq, 'part' => $part, 'backModal' => $backModal ?? null])
@endif
