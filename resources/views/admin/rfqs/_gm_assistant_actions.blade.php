{{--
    GM Assistant's own buttons on a part with them, as Data Entry's are
    (_data_entry_actions): Start before they've begun
    (_start_gm_assistant_button); while it's running, Pause — asking why, in
    the same popup as Data Entry's (_pause_data_entry_modal, which the page
    includes); its countdown stops, and they're free to start another — and
    Submit; once paused, Resume, which carries the countdown on from where it
    stopped — off while they've another part running, as Start is. Pause and
    Resume are whoever started it's (Admin's too, for them). See
    RfqController::startGmAssistant(), pauseGmAssistant(), resumeGmAssistant()
    and submitGmAssistantDetails().

    Expects: $rfq, $part. Optional: $myRunningGmAssistant (from the controller).
--}}
@php
    $pivot = $rfq->assigneeForPart((int) $part)?->pivot;
    $ownsIt = $pivot && (auth()->user()->hasRole('Admin') || (int) $pivot->gm_assistant_started_by === auth()->id());
    $runningElsewhere = $myRunningGmAssistant ?? null;
@endphp
@if ($pivot?->isGmAssistantPaused())
    @if ($ownsIt)
        <form action="{{ route('admin.rfqs.resume-gm-assistant', $rfq) }}" method="POST" class="d-inline-flex">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="{{ $part }}">
            <span @class(['d-inline-block start-hint', 'is-off' => $runningElsewhere]) tabindex="0"
                  title="{{ $runningElsewhere ? "You're on {$runningElsewhere->rfq->partNumberLabel($runningElsewhere->part_number)} — submit it, or pause it, before resuming this one." : 'Resume — its countdown carries on from where it stopped.' }}">
                <button type="submit" class="btn btn-sm btn-primary" @disabled($runningElsewhere)>
                    <i class="bi bi-play-fill"></i> Resume
                </button>
            </span>
        </form>
    @endif
@elseif ($pivot?->hasGmAssistantStarted())
    @if ($ownsIt)
        <button type="button" class="btn btn-sm btn-outline-secondary js-pause-data-entry" title="Pause — its countdown stops, and you can start another"
                data-bs-toggle="modal" data-bs-target="#pauseDataEntryModal"
                data-action="{{ route('admin.rfqs.pause-gm-assistant', $rfq) }}"
                data-part="{{ $part }}"
                data-rfq-number="{{ $rfq->partNumberLabel((int) $part) }}"
                data-subject="{{ $rfq->subject }}">
            <i class="bi bi-pause-fill"></i> Pause
        </button>
    @endif
    @include('admin.rfqs._gm_assistant_submit', ['rfq' => $rfq, 'part' => $part])
@else
    @include('admin.rfqs._start_gm_assistant_button', ['rfq' => $rfq, 'part' => $part])
@endif
