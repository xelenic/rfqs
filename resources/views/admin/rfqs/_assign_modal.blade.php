{{--
    Assign Sourcing wizard — shared between the index (list) and show (detail)
    pages. Four steps: (1) the job category, (2) whether to keep the task
    whole or split it into parts, (3) who takes each part, (4) a summary of
    it all — the only place Finish appears. Populated and driven by JS per
    row/page, see
    public/js/admin.js (.js-assign-rfq); the buttons that open it carry the
    RFQ's number, planned split, and current assignments as data-* attributes
    (see _assign_sourcing_button.blade.php).

    Once a split has been planned, reopening this skips straight to step 3 —
    the list of parts, where the still-empty ones can be filled in — since
    category and split size are settled by then; the summary still follows.
    See RfqController::assign().

    Expects: $sourcingUsers, $jobCategories (JobCategory models — the picker
    shows each one's description and icon).
    Optional: $statusFilter, $returnTo — see _edit_modal.blade.php.
--}}
<div class="modal fade" id="assignRfqModal" tabindex="-1" aria-labelledby="assignRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        {{-- The form *is* the .modal-content (rather than sitting inside it):
             the scrollable-modal layout — body scrolls, header and footer stay
             put — only applies to .modal-content's direct children, so with a
             wrapper in between a long parts list pushed the footer (and Finish)
             out of view. --}}
        <form method="POST" id="assignRfqForm" action="#" novalidate class="modal-content">
            @csrf
            @method('PATCH')
            @include('admin.rfqs._redirect_fields')
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
                        <span class="wizard-step-dot">1</span> <span class="wizard-step-label">Job category</span>
                    </li>
                    <li class="wizard-step" data-step-tab="2">
                        <span class="wizard-step-dot">2</span> <span class="wizard-step-label">Split</span>
                    </li>
                    <li class="wizard-step" data-step-tab="3">
                        <span class="wizard-step-dot">3</span> <span class="wizard-step-label">Assign parts</span>
                    </li>
                    <li class="wizard-step" data-step-tab="4">
                        <span class="wizard-step-dot">4</span> <span class="wizard-step-label">Review</span>
                    </li>
                </ol>

                <div class="modal-body">
                    {{-- Step 1 — job category. A card under the picker says what
                         the chosen category covers (its description). Anything
                         not in the list can be typed in, with a description of
                         its own; it's stored in job_categories when the wizard
                         finishes (RfqController::assign()). --}}
                    <div data-step-panel="1">
                        <div class="wizard-lead">
                            <span class="wizard-lead-icon"><i class="bi bi-tags"></i></span>
                            <div>
                                <div class="wizard-lead-title">What kind of job is this?</div>
                                <div class="wizard-lead-text">Pick the category that fits best — it tells Sourcing what kind of work to line up for this RFQ.</div>
                            </div>
                        </div>

                        <div class="wizard-rfq d-none" id="assign-rfq-context">
                            <span class="wizard-rfq-number" id="assign-rfq-context-number"></span>
                            <span class="wizard-rfq-subject" id="assign-rfq-context-subject"></span>
                        </div>

                        <label class="form-label" for="assign-category">Job category</label>
                        <select class="form-select" name="category" id="assign-category">
                            <option value="">Select a category</option>
                            @foreach ($jobCategories as $jobCategory)
                                <option value="{{ $jobCategory->name }}" data-icon="{{ $jobCategory->icon() }}" data-description="{{ $jobCategory->description }}">{{ $jobCategory->name }}</option>
                            @endforeach
                            <option value="{{ \App\Models\JobCategory::NEW_OPTION }}">+ Add a new category</option>
                        </select>

                        <div class="category-card is-empty" id="assign-category-card" aria-live="polite">
                            <span class="category-card-icon"><i class="bi bi-tag" id="assign-category-icon"></i></span>
                            <span class="category-card-body">
                                <span class="category-card-name" id="assign-category-name"></span>
                                <span class="category-card-desc" id="assign-category-desc"></span>
                            </span>
                            <span class="badge badge-soft-primary d-none" id="assign-category-badge">New</span>
                        </div>

                        <div class="mt-3 d-none" id="assign-new-category-wrap">
                            <label class="form-label" for="assign-new-category">New category name</label>
                            <input type="text" class="form-control" name="new_category" id="assign-new-category" maxlength="100" autocomplete="off" placeholder="e.g. Elevator Maintenance">

                            <label class="form-label mt-3" for="assign-new-category-description">
                                Description <span class="text-muted-soft fw-normal">(optional)</span>
                            </label>
                            <textarea class="form-control" name="new_category_description" id="assign-new-category-description" rows="2" maxlength="500"
                                      placeholder="What kind of work does this cover?"></textarea>
                            <div class="form-text d-flex justify-content-between gap-3">
                                <span>Saved to the category list when you finish, so it's there — with this description — next time.</span>
                                <span class="text-nowrap" id="assign-new-category-count">0 / 500</span>
                            </div>
                        </div>

                        <div class="text-danger small mt-2 d-none" id="assign-step-1-error" role="alert"></div>
                    </div>

                    {{-- Step 2 — keep the task whole, or split it into parts. A
                         split shows the parts it will make; who takes each one
                         is the next step. --}}
                    <div class="d-none" data-step-panel="2">
                        <div class="wizard-lead">
                            <span class="wizard-lead-icon"><i class="bi bi-diagram-3"></i></span>
                            <div>
                                <div class="wizard-lead-title">Keep it whole or split it up?</div>
                                <div class="wizard-lead-text">Splitting a big job lets several Sourcing members work on it at once — each part is tracked and completed on its own.</div>
                            </div>
                        </div>

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

                            {{-- How many parts: a big number with − / + around it, and
                                 one-tap picks for the usual counts. The number input is
                                 still the field that's submitted (and can be typed in). --}}
                            <div class="parts-counter d-none" id="assign-parts-count-wrap">
                                <div class="parts-counter-head">
                                    <label class="parts-counter-title" for="assign-parts-count">How many parts?</label>
                                    <span class="parts-counter-range">Between 2 and {{ \App\Models\Rfq::MAX_SPLIT_PARTS }}</span>
                                </div>

                                <div class="parts-counter-body">
                                    <div class="parts-stepper">
                                        <button type="button" class="parts-stepper-btn" id="assign-parts-minus" aria-label="One part fewer">
                                            <i class="bi bi-dash-lg"></i>
                                        </button>
                                        <input type="number" class="parts-stepper-input" name="parts" id="assign-parts-count"
                                               min="2" max="{{ \App\Models\Rfq::MAX_SPLIT_PARTS }}" value="2" inputmode="numeric">
                                        <button type="button" class="parts-stepper-btn" id="assign-parts-plus" aria-label="One part more">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>

                                    <div class="parts-quick" role="group" aria-label="Common numbers of parts">
                                        <span class="parts-quick-label">Quick pick</span>
                                        @foreach ([2, 3, 4, 5, 6, 8, 10] as $quickCount)
                                            @if ($quickCount <= \App\Models\Rfq::MAX_SPLIT_PARTS)
                                                <button type="button" class="parts-quick-btn" data-parts="{{ $quickCount }}">{{ $quickCount }}</button>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="wizard-parts-preview d-none" id="assign-parts-preview">
                                <div class="wizard-parts-preview-label">This makes</div>
                                <div class="wizard-chips" id="assign-parts-chips"></div>
                            </div>
                        </div>

                        <div class="text-danger small mt-2 d-none" id="assign-step-2-error" role="alert"></div>
                    </div>

                    {{-- Step 3 — who takes each part (or the whole task, if it
                         wasn't split): one row per part, its number on the left
                         and a Sourcing pick-list on the right. The lead-in and
                         summary are filled in by JS to match the choices made. --}}
                    <div class="d-none" data-step-panel="3">
                        <div class="wizard-lead">
                            <span class="wizard-lead-icon"><i class="bi bi-person-plus"></i></span>
                            <div>
                                <div class="wizard-lead-title" id="assign-lead-title"></div>
                                <div class="wizard-lead-text" id="assign-lead-text"></div>
                            </div>
                        </div>

                        <div class="wizard-chips wizard-summary" id="assign-summary"></div>

                        <div class="wizard-part-list" id="assign-parts-list"></div>

                        <div class="text-danger small mt-2 d-none" id="assign-step-3-error" role="alert"></div>
                    </div>

                    {{-- Step 4 — everything chosen, laid out to check before it's
                         saved. Filled in by JS from the earlier steps; each card's
                         Change jumps back to the step it came from. Finish (in the
                         footer) only appears here. --}}
                    <div class="d-none" data-step-panel="4">
                        <div class="wizard-lead">
                            <span class="wizard-lead-icon"><i class="bi bi-clipboard-check"></i></span>
                            <div>
                                <div class="wizard-lead-title">Review and confirm</div>
                                <div class="wizard-lead-text">Here's everything you've set up. Nothing is saved until you press <span id="review-finish-name">Finish</span>.</div>
                            </div>
                        </div>

                        <div class="wizard-rfq" id="review-rfq">
                            <span class="wizard-rfq-number" id="review-rfq-number"></span>
                            <span class="wizard-rfq-subject" id="review-rfq-subject"></span>
                        </div>

                        <div class="review">
                            <section class="review-card" id="review-category-card">
                                <header class="review-card-head">
                                    <span class="review-card-title"><i class="bi bi-tags"></i> Job category</span>
                                    <button type="button" class="review-edit" data-goto-step="1">Change</button>
                                </header>
                                <div class="review-card-body review-category">
                                    <span class="category-card-icon"><i class="bi bi-tag" id="review-category-icon"></i></span>
                                    <span class="category-card-body">
                                        <span class="category-card-name" id="review-category-name"></span>
                                        <span class="category-card-desc" id="review-category-desc"></span>
                                    </span>
                                    <span class="badge badge-soft-primary d-none" id="review-category-new">New</span>
                                </div>
                            </section>

                            <section class="review-card" id="review-split-card">
                                <header class="review-card-head">
                                    <span class="review-card-title"><i class="bi bi-diagram-3"></i> How it's handled</span>
                                    <button type="button" class="review-edit" data-goto-step="2">Change</button>
                                </header>
                                <div class="review-card-body">
                                    <div class="review-split-title" id="review-split-title"></div>
                                    <div class="review-split-text" id="review-split-text"></div>
                                </div>
                            </section>

                            <section class="review-card" id="review-assign-card">
                                <header class="review-card-head">
                                    <span class="review-card-title"><i class="bi bi-person-plus"></i> Who takes what</span>
                                    <button type="button" class="review-edit" data-goto-step="3">Change</button>
                                </header>
                                <div class="review-card-body">
                                    <ul class="review-parts" id="review-parts"></ul>
                                    <div class="review-note" id="review-note"></div>
                                </div>
                            </section>
                        </div>

                        <div class="text-danger small mt-2 d-none" id="assign-step-4-error" role="alert"></div>
                    </div>

                    {{-- The Sourcing pick-list every part's dropdown is cloned
                         from — rendered once here rather than once per part.
                         Each option carries the bare name for the summary. --}}
                    <template id="assign-user-options">
                        <option value="">Leave unassigned</option>
                        @foreach ($sourcingUsers as $sourcingUser)
                            <option value="{{ $sourcingUser->id }}" data-name="{{ $sourcingUser->name }}">{{ $sourcingUser->name }} — {{ $sourcingUser->pending_rfqs_count }} pending</option>
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
                    <button type="submit" class="btn btn-primary wizard-finish d-none" id="assign-wizard-finish">Finish</button>
                </div>
            @endif
        </form>
    </div>
</div>
