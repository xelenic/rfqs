{{--
    Assign Sourcing wizard — shared between the index (list) and show (detail)
    pages. Two steps: (1) the job category, (2) whether to split the task and
    who takes which part. Populated and driven by JS per row/page, see
    public/js/admin.js (.js-assign-rfq); the buttons that open it carry the
    RFQ's number, planned split, and current assignments as data-* attributes
    (see _assign_sourcing_button.blade.php).

    Once a split has been planned, reopening this skips straight to a list of
    the parts and lets the still-empty ones be filled in — category and split
    size are settled by then. See RfqController::assign().

    Expects: $sourcingUsers, $jobCategories.
    Optional: $statusFilter, $returnTo — see _edit_modal.blade.php.
--}}
<div class="modal fade" id="assignRfqModal" tabindex="-1" aria-labelledby="assignRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <form method="POST" id="assignRfqForm" action="#" novalidate>
                @csrf
                @method('PATCH')
                @if ($statusFilter ?? null)
                    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                @endif
                @if (($returnTo ?? null) === 'show')
                    <input type="hidden" name="return_to" value="show">
                @endif
                <input type="hidden" name="split" id="assign-split-input" value="0">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="assignRfqModalLabel">Assign Sourcing</h5>
                        <div class="text-muted-soft small" id="assign-wizard-subtitle"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                @if ($sourcingUsers->isEmpty())
                    <div class="modal-body">
                        <p class="text-muted-soft mb-0">No users have the Sourcing role yet. Assign that role from the Users page first.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                @else
                    <ol class="wizard-steps" id="assign-wizard-steps">
                        <li class="wizard-step is-active" data-step-tab="1">
                            <span class="wizard-step-dot">1</span> Job category
                        </li>
                        <li class="wizard-step" data-step-tab="2">
                            <span class="wizard-step-dot">2</span> Split &amp; assign
                        </li>
                    </ol>

                    <div class="modal-body">
                        {{-- Step 1 — job category. Anything not in the list can be
                             typed in; it's stored in job_categories when the wizard
                             finishes (RfqController::assign()). --}}
                        <div data-step-panel="1">
                            <label class="form-label" for="assign-category">What kind of job is this?</label>
                            <select class="form-select" name="category" id="assign-category">
                                <option value="">Select a category</option>
                                @foreach ($jobCategories as $categoryName)
                                    <option value="{{ $categoryName }}">{{ $categoryName }}</option>
                                @endforeach
                                <option value="{{ \App\Models\JobCategory::NEW_OPTION }}">+ Add a new category</option>
                            </select>

                            <div class="mt-3 d-none" id="assign-new-category-wrap">
                                <label class="form-label" for="assign-new-category">New category name</label>
                                <input type="text" class="form-control" name="new_category" id="assign-new-category" maxlength="100" autocomplete="off">
                                <div class="form-text">Saved to the category list when you finish, so it's in the dropdown next time.</div>
                            </div>

                            <div class="text-danger small mt-2 d-none" id="assign-step-1-error" role="alert"></div>
                        </div>

                        {{-- Step 2 — keep whole, or split into parts and assign each. --}}
                        <div class="d-none" data-step-panel="2">
                            <div id="assign-split-choice">
                                <div class="wizard-choice-group">
                                    <label class="wizard-choice">
                                        <input type="radio" name="split_choice" value="single" checked>
                                        <span class="wizard-choice-body">
                                            <span class="wizard-choice-title"><i class="bi bi-person"></i> Keep as one task</span>
                                            <span class="wizard-choice-desc">One Sourcing member takes the whole RFQ.</span>
                                        </span>
                                    </label>
                                    <label class="wizard-choice">
                                        <input type="radio" name="split_choice" value="split">
                                        <span class="wizard-choice-body">
                                            <span class="wizard-choice-title"><i class="bi bi-diagram-3"></i> Split this task</span>
                                            <span class="wizard-choice-desc">Break it into parts and assign each one separately.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="mt-3 d-none" id="assign-parts-count-wrap">
                                    <label class="form-label" for="assign-parts-count">How many parts?</label>
                                    <input type="number" class="form-control assign-parts-count" name="parts" id="assign-parts-count"
                                           min="2" max="{{ \App\Models\Rfq::MAX_SPLIT_PARTS }}" value="2">
                                </div>
                            </div>

                            <div class="wizard-part-list" id="assign-parts-list"></div>
                            <div class="form-text mt-2" id="assign-parts-hint"></div>

                            <div class="text-danger small mt-2 d-none" id="assign-step-2-error" role="alert"></div>
                        </div>

                        {{-- The Sourcing pick-list every part's dropdown is cloned
                             from — rendered once here rather than once per part. --}}
                        <template id="assign-user-options">
                            <option value="">Leave unassigned</option>
                            @foreach ($sourcingUsers as $sourcingUser)
                                <option value="{{ $sourcingUser->id }}">{{ $sourcingUser->name }} — {{ $sourcingUser->pending_rfqs_count }} pending</option>
                            @endforeach
                        </template>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary me-auto d-none" id="assign-wizard-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="assign-wizard-next">
                            Next <i class="bi bi-arrow-right"></i>
                        </button>
                        <button type="submit" class="btn btn-primary d-none" id="assign-wizard-finish">Finish</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
</div>
