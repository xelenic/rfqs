{{--
    The prompt every Mark Complete and Return to Sourcing opens — Sourcing's on
    their own part, Data Entry's on a Sourcing part (to complete it, or to send
    it back with a reason). One shared modal, pointed at a part and dressed for
    the action by JS when it opens (see admin.js, .js-complete and
    _complete_button.blade.php). It asks for a comment for whoever's next in
    line, posted to the RFQ's thread along with the action — there's no
    separate comment box in the quick-detail modal — and, for Data Entry's
    Send to Finalize and Return to Sourcing, how many line items they added
    (so far, when sending it back); for Assign to Data Entry, whom there it's
    for. Admin also gets a "Done by"
    here — Data Entry's members for Data Entry's actions, the part's assigned
    member for a Sourcing Mark Complete. See RfqController::completeSourcing(),
    completeDataEntry() and returnSourcing().
--}}
<div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="completeForm" action="#" novalidate class="modal-content" enctype="multipart/form-data">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="">
            <input type="hidden" name="return_to" value="">
            <input type="hidden" name="redirect_status" value="">
            <input type="hidden" name="redirect_view" value="">
            <input type="hidden" name="redirect_role" value="">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="completeModalLabel">Assign to Data Entry</h5>
                    <div class="text-muted-soft small" id="complete-subtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="wizard-lead">
                    <span class="wizard-lead-icon"><i class="bi bi-chat-left-text" id="complete-lead-icon"></i></span>
                    <div>
                        <div class="wizard-lead-title" id="complete-lead-title"></div>
                        <div class="wizard-lead-text" id="complete-hint"></div>
                    </div>
                </div>

                {{-- Data Entry's Send to Finalize and Return to Sourcing ask how many line
                     items they added too (RfqController::completeDataEntry(),
                     returnSourcing()); hidden, and not sent, for the rest. --}}
                <div class="mb-3 d-none" id="complete-entries">
                    <label class="form-label" for="complete-entries-input">Line items added</label>
                    <div class="input-group complete-entries-group">
                        <span class="input-group-text"><i class="bi bi-list-ol" aria-hidden="true"></i></span>
                        <input type="number" name="entries" id="complete-entries-input" class="form-control" min="0"
                               max="{{ \App\Http\Controllers\Admin\RfqController::MAX_DATA_ENTRIES }}" step="1" inputmode="numeric" disabled
                               aria-describedby="complete-entries-hint">
                        <span class="input-group-text">line items</span>
                    </div>
                    <div class="form-text" id="complete-entries-hint">How many line items you added to this part.</div>
                    <div class="text-danger small mt-1 d-none" id="complete-entries-error" role="alert"></div>
                </div>

                {{-- Assign to Data Entry: whom there it's for — someone by name, who
                     alone sees and starts it and is told; or anyone there. Each
                     with what's already theirs. Shown, and sent, for that action
                     alone (admin.js). See RfqController::completeSourcing(). --}}
                @if (auth()->user()->hasAnyRole(['Sourcing', 'Admin']))
                    @php
                        $dataEntryPeople = \App\Models\User::query()->holdingRole('Data Entry')->orderBy('name')->get();
                        $waitingFor = \Illuminate\Support\Facades\DB::table('rfq_user')
                            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
                            ->where('rfqs.status', 'Pending')
                            ->whereNull('rfq_user.status')
                            ->whereNotNull('rfq_user.completed_at')
                            ->whereNull('rfq_user.data_entry_completed_at')
                            ->whereNull('rfq_user.data_entry_started_at')
                            ->whereNotNull('rfq_user.data_entry_assigned_to')
                            ->groupBy('rfq_user.data_entry_assigned_to')
                            ->selectRaw('rfq_user.data_entry_assigned_to as person, count(*) as total')
                            ->pluck('total', 'person');
                        $runningFor = \App\Models\RfqStep::query()->where('step', 'data_entry')->whereNull('ended_at')->pluck('worked_by')->countBy();
                    @endphp
                    {{-- How many line items Data Entry has to add — asked, and sent,
                         for Assign to Data Entry alone (admin.js). --}}
                    <div class="mb-3 d-none" id="complete-line-items">
                        <label class="form-label" for="complete-line-items-input">Line items to be added</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-list-ol" aria-hidden="true"></i></span>
                            <input type="number" name="line_items" id="complete-line-items-input" class="form-control" min="1"
                                   max="{{ \App\Http\Controllers\Admin\RfqController::MAX_LINE_ITEMS }}" step="1" inputmode="numeric" disabled
                                   aria-describedby="complete-line-items-hint">
                            <span class="input-group-text">line items</span>
                        </div>
                        @php $minutesPerItem = rtrim(rtrim(number_format(\App\Models\Setting::dataEntryPerItem()['seconds'] / 60, 1), '0'), '.'); @endphp
                        <div class="form-text" id="complete-line-items-hint">How many line items Data Entry has to add for this part — their time on it is these × {{ $minutesPerItem }} min{{ \App\Models\Setting::perItemPeople('data_entry') !== [] ? ', or the Data Entry person\'s own time an item where they have one' : '' }}.</div>
                        <div class="text-danger small mt-1 d-none" id="complete-line-items-error" role="alert"></div>
                    </div>

                    <div class="mb-3 d-none" id="complete-data-entry">
                        <label class="form-label" for="complete-data-entry-select">Data Entry person</label>
                        <select name="data_entry_user_id" id="complete-data-entry-select" class="form-select" disabled>
                            <option value="">Anyone in Data Entry — whoever starts it first</option>
                            @foreach ($dataEntryPeople as $person)
                                @php
                                    $load = collect([
                                        ($waitingFor[$person->id] ?? 0) > 0 ? $waitingFor[$person->id].' waiting' : null,
                                        ($runningFor[$person->id] ?? 0) > 0 ? 'on a part now' : null,
                                    ])->filter()->join(' · ');
                                @endphp
                                <option value="{{ $person->id }}">{{ $person->name }}{{ $load !== '' ? ' — '.$load : ' — free' }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Someone by name sees it on their list, gets an alert, and is the one to start it.</div>
                    </div>
                @endif

                <label class="form-label" for="complete-comment" id="complete-label">Comment</label>
                <textarea name="comment" id="complete-comment" rows="4" maxlength="2000" class="form-control"></textarea>
                <div class="form-text d-flex justify-content-between gap-3">
                    <span>Posted to this RFQ's comments, where <span id="complete-audience"></span> will read it.</span>
                    <span class="text-nowrap" id="complete-count">0 / 2000</span>
                </div>
                <div class="text-danger small mt-2 d-none" id="complete-error" role="alert"></div>

                @include('admin.rfqs._attachments_input', ['attachmentsId' => 'complete-attachments'])

                {{-- Admin's "Done by" — JS shows whichever one the button that
                     opened this asks for (data-actor-role) and lets only that
                     one be sent. Data Entry's actions pick from its members; a
                     Sourcing Mark Complete names the part's assigned member,
                     filled in from the button. --}}
                @if (auth()->user()->hasRole('Admin'))
                    <div class="mt-3 d-none" id="complete-actor">
                        @include('admin.rfqs._acting_as', ['role' => 'Data Entry', 'id' => 'complete-acting-as', 'label' => 'Done by', 'disabled' => true])
                    </div>
                    <div class="mt-3 d-none" id="complete-assignee">
                        <label for="complete-assignee-select" class="form-label">
                            Done by <span class="text-muted-soft fw-normal">(Sourcing)</span>
                        </label>
                        <select name="acting_user_id" id="complete-assignee-select" class="form-select" disabled></select>
                        <div class="form-text">The Sourcing member this part is assigned to — recorded as completing it.</div>
                    </div>
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="complete-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="complete-submit">
                    <i class="bi bi-check2-circle"></i> Assign to Data Entry
                </button>
            </div>
        </form>
    </div>
</div>
