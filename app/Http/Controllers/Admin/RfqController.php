<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RfqController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:rfqs.view', only: ['index', 'show']),
            new Middleware('permission:rfqs.create', only: ['store']),
            new Middleware('permission:rfqs.edit', only: ['update', 'assign', 'assignOperations', 'completeSourcing', 'returnSourcing', 'completeDataEntry', 'completeSeniorOpsReview', 'approveHeadOfBd', 'rejectHeadOfBd', 'submitGmAssistantDetails', 'approveGm', 'close']),
            new Middleware('permission:rfqs.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::STATUSES, true)) {
            $status = null;
        }

        // A Sourcing member's Pending list is their own queue, not the whole
        // company's backlog — scoped to RFQs they're actually assigned to.
        // Completed and the unfiltered list stay company-wide.
        //
        // When Operations splits an RFQ across several Sourcing members,
        // one of them marking it complete hands the whole RFQ to Data
        // Entry — but it must stay visible to the others too (as their own
        // split reference, see the "Handed off" note in the view), not
        // vanish from their queue just because a teammate finished first.
        //
        // "Returns" (below) is a separate lens on the same Pending status,
        // so the two are mutually exclusive rather than double-counting.
        $scopedToReturns = $request->user()->hasRole('Sourcing') && $request->query('view') === 'returns';
        $scopedToMe = $status === 'Pending' && $request->user()->hasRole('Sourcing') && ! $scopedToReturns;

        // Data Entry's Pending list is only what Sourcing has actually
        // handed off — RFQs still with Operations or in progress with
        // Sourcing aren't theirs to act on yet.
        $scopedToDataEntry = $status === 'Pending' && $request->user()->hasRole('Data Entry');

        // Senior Operations' second review — RFQs where every assignee's
        // split is both Sourcing- and Data-Entry-complete, waiting on
        // approval before escalating to Head of Business Development. A
        // second lens on Senior Operations' own Pending status, alongside
        // "Unassigned" below — mutually exclusive via ?view=review, same
        // pattern as Sourcing's "Returns".
        $scopedToSeniorOpsReview = $status === 'Pending' && $request->user()->hasRole('Senior Operations') && $request->query('view') === 'review';

        // Operations' Pending list is just their actionable backlog — RFQs
        // nobody's assigned to Sourcing yet — not every Pending RFQ in the
        // company regardless of stage. Matches the red count badge in the
        // sidebar (layouts/app.blade.php).
        $scopedToUnassigned = $status === 'Pending' && $request->user()->hasRole('Senior Operations') && ! $scopedToSeniorOpsReview;

        // Head of Business Development's Pending list — RFQs Senior
        // Operations has approved, waiting on their own approve/reject
        // decision. Unlike Senior Operations (which also has "Unassigned"),
        // this is Head of BD's only queue, so it's their whole Pending page
        // rather than a ?view= toggle.
        $scopedToHeadOfBdReview = $status === 'Pending' && $request->user()->hasRole('Head of Business Development');

        // GM Assistant's Pending list — RFQs Head of Business Development
        // has approved, waiting on client details and payment terms before
        // forwarding to the General Manager. Their only queue, same as
        // Head of Business Development above.
        $scopedToGmAssistant = $status === 'Pending' && $request->user()->hasRole('GM Assistant');

        // General Manager's Pending list — RFQs GM Assistant has finished
        // adding client details/payment terms to, waiting on final
        // executive approval. Their only queue, same as Head of Business
        // Development/GM Assistant above.
        $scopedToGmReview = $status === 'Pending' && $request->user()->hasRole('General Manager');

        // Business Development's own reference — RFQs the General Manager
        // has approved, ready for BD to send to the client and formally
        // close out. BD also sees the full company-wide Pending list by
        // default (they may be tracking RFQs at any stage), so this is a
        // second lens via ?view=closing, same pattern as Sourcing's
        // "Returns" and Senior Operations' "Review".
        $scopedToBdClosing = $status === 'Pending' && $request->user()->hasRole('Business Development') && $request->query('view') === 'closing';

        $search = $request->string('search')->trim()->toString();

        $applyCommonFilters = function ($query) use ($status, $search) {
            $query->when($status, fn ($query, $status) => $query->where('status', $status))
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('wc_number', 'like', "%{$search}%")
                            ->orWhere('rfq_number', 'like', "%{$search}%")
                            ->orWhere('subject', 'like', "%{$search}%");
                    });
                });
        };

        $rfqs = Rfq::query()
            ->with(['assignees', 'parts', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'seniorOpsReviewedBy', 'headOfBdApprovedBy', 'gmAssistantCompletedBy', 'gmApprovedBy'])
            // The quick-detail modal (Data Entry's "By Sourcing" list and
            // Sourcing's own "My Pending RFQs") shows a comment thread
            // scoped to one assignee — only worth the extra eager load on
            // those two views.
            ->when($scopedToDataEntry || $scopedToMe, fn ($query) => $query->with(['comments.author', 'comments.replies.author']))
            ->tap($applyCommonFilters)
            ->when($scopedToMe, function ($query) use ($request) {
                $query->whereHas('assignees', fn ($q) => $q->whereKey($request->user()->id));
            })
            ->when($scopedToReturns, function ($query) use ($request) {
                $query->whereHas('assignees', function ($q) use ($request) {
                    $q->whereKey($request->user()->id)
                        ->whereNotNull('rfq_user.returned_at')
                        ->whereNull('rfq_user.completed_at');
                });
            })
            ->when($scopedToDataEntry, fn ($query) => $query->whereNotNull('sourcing_completed_at'))
            ->when($scopedToUnassigned, fn ($query) => $query->needingSourcing())
            ->when($scopedToHeadOfBdReview, fn ($query) => $query->where('stage', 'head_of_bd_review'))
            ->when($scopedToGmAssistant, fn ($query) => $query->where('stage', 'gm_assistant'))
            ->when($scopedToGmReview, fn ($query) => $query->where('stage', 'gm_review'))
            ->when($scopedToBdClosing, fn ($query) => $query->where('stage', 'bd_closing'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        // Data Entry's "By Sourcing" tab shows each Sourcing assignee's
        // completed part as soon as *they* finish it — each by each —
        // rather than waiting for every assignee on a split RFQ to be
        // done, like the Detailed view (above) does. An RFQ can show up
        // here before it's fully handed off and appears there.
        $bySourcingRfqs = $scopedToDataEntry
            ? Rfq::query()
                ->with(['assignees', 'parts', 'comments.author', 'comments.replies.author'])
                // Only assignees Sourcing has finished but Data Entry
                // hasn't processed yet — once Data Entry completes one
                // assignee's split, it drops out of this queue on its own,
                // without touching any other assignee's split on the same
                // RFQ. See Rfq::completeDataEntryPartFor().
                ->whereHas('assignees', fn ($q) => $q->whereNotNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'))
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'sourcing_page')
                ->withQueryString()
            : null;

        // Operations' "Assigned" tab — a reference view alongside
        // "Unassigned" (above) of what's already been routed to Sourcing
        // but is still Pending overall.
        $assignedRfqs = $scopedToUnassigned
            ? Rfq::query()
                ->with(['assignees', 'parts', 'creator', 'operationsAssignee', 'sourcingCompletedBy'])
                ->fullySourced()
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'assigned_page')
                ->withQueryString()
            : null;

        $seniorOpsReviewRfqs = $scopedToSeniorOpsReview
            ? Rfq::query()
                ->with(['assignees', 'creator', 'operationsAssignee', 'dataEntryCompletedBy'])
                ->where('stage', 'senior_ops_review')
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'review_page')
                ->withQueryString()
            : null;

        return view('admin.rfqs.index', [
            'rfqs' => $rfqs,
            'bySourcingRfqs' => $bySourcingRfqs,
            'assignedRfqs' => $assignedRfqs,
            'seniorOpsReviewRfqs' => $seniorOpsReviewRfqs,
            'search' => $search,
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'scopedToMe' => $scopedToMe,
            'scopedToReturns' => $scopedToReturns,
            'scopedToSeniorOpsReview' => $scopedToSeniorOpsReview,
            'scopedToDataEntry' => $scopedToDataEntry,
            'scopedToUnassigned' => $scopedToUnassigned,
            'scopedToHeadOfBdReview' => $scopedToHeadOfBdReview,
            'scopedToGmAssistant' => $scopedToGmAssistant,
            'scopedToGmReview' => $scopedToGmReview,
            'scopedToBdClosing' => $scopedToBdClosing,
            'sourcingUsers' => User::role('Sourcing')->withSourcingWorkloadCounts()->orderBy('name')->get(),
            'jobCategories' => JobCategory::orderBy('name')->pluck('name'),
            'operationsUsers' => User::role('Senior Operations')->orderBy('name')->get(),
            'nextRfqNumber' => Rfq::nextRfqNumber(),
        ]);
    }

    public function show(Request $request, Rfq $rfq): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::STATUSES, true)) {
            $status = null;
        }

        return view('admin.rfqs.show', [
            'rfq' => $rfq->load(['assignees', 'parts', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'dataEntryCompletedBy', 'comments.author.roles', 'comments.replies.author.roles']),
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'sourcingUsers' => User::role('Sourcing')->withSourcingWorkloadCounts()->orderBy('name')->get(),
            'jobCategories' => JobCategory::orderBy('name')->pluck('name'),
            'operationsUsers' => User::role('Senior Operations')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // RFQ Number is auto-generated (WP0001, WP0002, ...), never taken
        // from user input — see Rfq::nextRfqNumber().
        $validator = Validator::make($request->all(), $this->rules(requireRfqNumber: false));

        if ($validator->fails()) {
            return back()->withErrors($validator, 'create')->withInput();
        }

        Rfq::create([
            ...$validator->validated(),
            'rfq_number' => Rfq::nextRfqNumber(),
            'created_by' => $request->user()->id,
        ]);

        return $this->redirectToIndex($request)->with('status', 'RFQ created successfully.');
    }

    public function update(Request $request, Rfq $rfq): RedirectResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return back()->withErrors($validator, 'edit')->withInput();
        }

        $rfq->update($validator->validated());

        return $this->redirectAfterSave($request, $rfq)->with('status', 'RFQ updated successfully.');
    }

    public function destroy(Request $request, Rfq $rfq): RedirectResponse
    {
        $rfq->delete();

        return $this->redirectToIndex($request)->with('status', 'RFQ deleted successfully.');
    }

    /**
     * The Assign Sourcing wizard's submit. Two passes over the same
     * endpoint:
     *
     * - First (no split planned yet): the job category, whether to split
     *   the task and into how many parts, and who takes which part. A
     *   category typed in by hand is stored in job_categories so it's in
     *   the dropdown next time. Parts can be left empty.
     * - Later (split already planned): only fills parts still empty —
     *   category and split size are settled by then, and assigned parts
     *   are never reassigned here.
     *
     * Only users who actually hold the Sourcing role are ever assigned,
     * even if the request is tampered with. One person can take several
     * parts — they're worked and completed together, as that person's one
     * share of the RFQ (rfq_user is one row per person per RFQ) — as long
     * as they haven't already finished their share. Sourcing itself never
     * has access to this — it's a receiving role, not an assigning one.
     *
     * An Operations member assigning Sourcing directly is implicitly the
     * one routing this RFQ — if nobody's recorded as the Operations
     * assignee yet, record them, so Operations doesn't need a separate
     * "Assign Operations" step just to name themselves.
     */
    public function assign(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');

        $planning = $rfq->split_count === null;

        abort_if(
            $planning && $rfq->assignees->isNotEmpty(),
            422,
            'This RFQ was assigned before parts could be planned up front, so its Sourcing assignment can\'t be changed here.'
        );

        $splitting = $planning && $request->boolean('split');
        $newCategory = $planning && $request->input('category') === JobCategory::NEW_OPTION;

        $validator = Validator::make($request->all(), [
            'category' => [Rule::requiredIf($planning), 'nullable', 'string', 'max:100'],
            'new_category' => [Rule::requiredIf($newCategory), 'nullable', 'string', 'max:100'],
            'split' => ['nullable', 'boolean'],
            'parts' => [Rule::requiredIf($splitting), 'nullable', 'integer', 'min:2', 'max:'.Rfq::MAX_SPLIT_PARTS],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['nullable', 'integer'],
        ]);

        $totalParts = $planning ? ($splitting ? (int) $request->input('parts') : 1) : $rfq->split_count;
        $assignments = collect($request->input('assignments', []))
            ->mapWithKeys(fn ($userId, $part) => [(int) $part => $userId ? (int) $userId : null]);

        $validator->after(function ($validator) use ($request, $rfq, $planning, $splitting, $newCategory, $totalParts, $assignments) {
            if ($planning && ! $newCategory && $request->filled('category') && ! JobCategory::where('name', $request->input('category'))->exists()) {
                $validator->errors()->add('category', 'That job category doesn\'t exist.');
            }

            if ($planning && ! $splitting && ! $assignments->get(1)) {
                $validator->errors()->add('assignments', 'Pick a Sourcing member to take this task, or split it into parts.');
            }

            if ($assignments->keys()->contains(fn (int $part) => $part < 1 || $part > $totalParts)) {
                $validator->errors()->add('assignments', 'One of those parts doesn\'t exist on this RFQ.');
            }

            $pickedUserIds = $assignments->filter()->values()->unique();

            if ($pickedUserIds->diff(User::role('Sourcing')->pluck('id'))->isNotEmpty()) {
                $validator->errors()->add('assignments', 'Only users with the Sourcing role can be assigned.');
            }

            // A person can hold several parts — they're one share, completed
            // together — but a share that's already finished can't quietly
            // grow: the new part would un-finish work Data Entry may already
            // have processed.
            $finishedShareNames = $rfq->assignees
                ->filter(fn (User $assignee) => $assignee->pivot->completed_at !== null && $pickedUserIds->contains($assignee->id))
                ->pluck('name');

            if ($finishedShareNames->isNotEmpty()) {
                $validator->errors()->add('assignments', $finishedShareNames->join(', ', ' and ').' already finished their part, so can\'t take another one — pick someone else.');
            }
        });

        if ($validator->fails()) {
            return back()->with('error', implode(' ', $validator->errors()->all()));
        }

        DB::transaction(function () use ($request, $rfq, $planning, $newCategory, $totalParts, $assignments) {
            $user = $request->user();

            if ($planning) {
                $category = JobCategory::findOrCreateByName(
                    $newCategory ? $request->input('new_category') : $request->input('category'),
                    $user
                );

                $rfq->categorize($category->name, $user);
                $rfq->planSplit($totalParts);
            }

            $rfq->assignSourcingParts($assignments->all());

            if ($user->hasRole('Senior Operations') && ! $rfq->operations_assigned_by) {
                $rfq->update([
                    'operations_assigned_by' => $user->id,
                    'operations_assigned_at' => now(),
                ]);
            }
        });

        $remaining = $rfq->sourcingParts()->whereNull('assignee')->count();

        return $this->redirectAfterSave($request, $rfq)->with('status', $remaining === 0
            ? 'Sourcing assigned.'
            : 'Saved — '.($totalParts - $remaining).' of '.$totalParts.' parts assigned, '.$remaining.' still to assign.');
    }

    /**
     * Assign (or clear) the single Operations-team member who routed this
     * RFQ, ahead of Sourcing assignment. Only a user who actually holds the
     * Operations role is ever recorded, even if the request is tampered
     * with. Selecting "None" clears the assignment. Sourcing itself never
     * has access to this — it's a receiving role, not an assigning one.
     */
    public function assignOperations(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');

        $validated = $request->validate([
            'operations_user' => ['nullable', 'integer'],
        ]);

        $operationsUserId = $validated['operations_user'] ?? null;
        $isValidOperationsUser = $operationsUserId
            && User::role('Senior Operations')->whereKey($operationsUserId)->exists();

        $rfq->update([
            'operations_assigned_by' => $isValidOperationsUser ? $operationsUserId : null,
            'operations_assigned_at' => $isValidOperationsUser ? now() : null,
        ]);

        return $this->redirectAfterSave($request, $rfq)->with('status', 'Operations assignment updated.');
    }

    /**
     * A Sourcing member assigned to this RFQ marks their own split of the
     * work done. Only an assigned Sourcing member may do this, and only
     * once per person — the button disappears once their part is set. When
     * the RFQ is split across several Sourcing members, each of them has
     * to complete their own part; only once everyone has does the RFQ
     * actually hand off to Data Entry (see Rfq::completeSourcingPartFor()).
     */
    public function completeSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('Sourcing') && $rfq->assignees->contains('id', $user->id),
            403,
            'Only a Sourcing member assigned to this RFQ can mark it complete.'
        );

        $rfq->completeSourcingPartFor($user);

        $status = $rfq->isWithDataEntry()
            ? 'Marked complete — handed off to Data Entry.'
            : 'Your part is marked complete — waiting on the rest of the Sourcing team.';

        return $this->redirectAfterSave($request, $rfq)->with('status', $status);
    }

    /**
     * Data Entry sends one Sourcing assignee's split of this RFQ back for
     * rework, with a required reason. Clears that assignee's completed_at
     * (Mark Complete becomes available to them again, and it shows up in
     * their "Returns" list) and, if the RFQ had already fully handed off
     * to Data Entry, undoes that too — see Rfq::returnSourcingPartFor().
     */
    public function returnSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Data Entry', 'Admin']),
            403,
            'Only Data Entry can send an RFQ back to Sourcing.'
        );

        $validated = $request->validateWithBag('return', [
            'assignee_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $assignee = $rfq->assignees->firstWhere('id', (int) $validated['assignee_id']);

        abort_unless($assignee, 404, 'That Sourcing assignee is not on this RFQ.');

        $rfq->returnSourcingPartFor($assignee, $validated['reason'], $request->user());

        return redirect()->back()->with('status', "Sent {$assignee->name}'s part back to Sourcing.");
    }

    /**
     * Data Entry finishes processing one Sourcing assignee's split — only
     * that split, never the others on the same RFQ. The RFQ as a whole
     * only formally closes out (status becomes Completed, dropping it out
     * of "Ready for Data Entry" and into "Completed RFQs") once every
     * assignee's split has been completed here. See
     * Rfq::completeDataEntryPartFor().
     */
    public function completeDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Data Entry', 'Admin']),
            403,
            'Only Data Entry can complete an RFQ.'
        );

        $validated = $request->validate([
            'assignee_id' => ['required', 'integer'],
        ]);

        $assignee = $rfq->assignees->firstWhere('id', (int) $validated['assignee_id']);

        abort_unless($assignee, 404, 'That Sourcing assignee is not on this RFQ.');

        $rfq->completeDataEntryPartFor($assignee, $request->user());

        return redirect()->back()->with('status', "Marked {$assignee->name}'s part complete.");
    }

    /**
     * Senior Operations' second review — every assignee's split is both
     * Sourcing- and Data-Entry-complete; approving here escalates the RFQ
     * on to Head of Business Development. See Rfq::completeSeniorOpsReview().
     */
    public function completeSeniorOpsReview(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Senior Operations', 'Admin']),
            403,
            'Only Senior Operations can approve this review.'
        );
        abort_unless($rfq->stage === 'senior_ops_review', 422, 'This RFQ is not awaiting Senior Operations review.');

        $rfq->completeSeniorOpsReview($request->user());

        return redirect()->back()->with('status', 'Approved — escalated to Head of Business Development.');
    }

    /**
     * Head of Business Development approves — escalates the RFQ on to GM
     * Assistant. See Rfq::approveByHeadOfBd().
     */
    public function approveHeadOfBd(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Head of Business Development', 'Admin']),
            403,
            'Only Head of Business Development can approve here.'
        );
        abort_unless($rfq->stage === 'head_of_bd_review', 422, 'This RFQ is not awaiting Head of Business Development review.');

        $rfq->approveByHeadOfBd($request->user());

        return redirect()->back()->with('status', 'Approved — escalated to GM Assistant.');
    }

    /**
     * Head of Business Development rejects — sends the RFQ back to an
     * earlier stage (Sourcing, Data Entry, or Senior Operations' own
     * review) with a reason. See Rfq::rejectToStage().
     */
    public function rejectHeadOfBd(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Head of Business Development', 'Admin']),
            403,
            'Only Head of Business Development can reject here.'
        );
        abort_unless($rfq->stage === 'head_of_bd_review', 422, 'This RFQ is not awaiting Head of Business Development review.');

        $validated = $request->validateWithBag('reject', [
            'target_stage' => ['required', 'in:'.implode(',', Rfq::REJECT_TARGET_STAGES)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $rfq->rejectToStage($validated['target_stage'], $validated['reason'], $request->user());

        return redirect()->back()->with('status', 'Sent back to '.Rfq::stageLabel($validated['target_stage']).'.');
    }

    /**
     * GM Assistant records this RFQ's client details and payment terms and
     * forwards it on to the General Manager. See
     * Rfq::recordGmAssistantDetails().
     */
    public function submitGmAssistantDetails(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['GM Assistant', 'Admin']),
            403,
            'Only GM Assistant can add these details.'
        );
        abort_unless($rfq->stage === 'gm_assistant', 422, 'This RFQ is not awaiting GM Assistant details.');

        $validated = $request->validateWithBag('gm_assistant', [
            'client_details' => ['required', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
        ]);

        $rfq->recordGmAssistantDetails($request->user(), $validated['client_details'], $validated['payment_terms'] ?? null);

        return redirect()->back()->with('status', 'Forwarded to General Manager.');
    }

    /**
     * General Manager gives final approval — the RFQ is now ready for
     * Business Development to close out. See Rfq::approveByGm().
     */
    public function approveGm(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['General Manager', 'Admin']),
            403,
            'Only the General Manager can give final approval.'
        );
        abort_unless($rfq->stage === 'gm_review', 422, 'This RFQ is not awaiting General Manager approval.');

        $rfq->approveByGm($request->user());

        return redirect()->back()->with('status', 'Approved — ready for Business Development to close.');
    }

    /**
     * Business Development formally closes this RFQ out — the true end of
     * the lifecycle. See Rfq::closeOut().
     */
    public function close(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Business Development', 'Admin']),
            403,
            'Only Business Development can close an RFQ.'
        );
        abort_unless($rfq->stage === 'bd_closing', 422, 'This RFQ is not ready to close.');

        $rfq->closeOut($request->user());

        return redirect()->back()->with('status', 'RFQ closed.');
    }

    /**
     * After an edit or assignment, send the user back to the RFQ's detail
     * page if that's where they started, otherwise back to the list.
     */
    protected function redirectAfterSave(Request $request, Rfq $rfq): RedirectResponse
    {
        if ($request->input('return_to') === 'show') {
            return redirect()->route('admin.rfqs.show', $rfq);
        }

        return $this->redirectToIndex($request);
    }

    /**
     * Send the user back to whichever RFQ list (all / pending / completed)
     * they were on, rather than always dropping them on the unfiltered list.
     */
    protected function redirectToIndex(Request $request): RedirectResponse
    {
        $status = $request->input('redirect_status');

        return redirect()->route('admin.rfqs.index', in_array($status, Rfq::STATUSES, true) ? ['status' => $status] : []);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(bool $requireRfqNumber = true): array
    {
        return [
            'wc_number' => ['required', 'string', 'max:255'],
            'rfq_number' => [$requireRfqNumber ? 'required' : 'nullable', 'string', 'max:255'],
            'priority_level' => ['required', 'string', 'in:'.implode(',', Rfq::PRIORITIES)],
            'status' => ['required', 'string', 'in:'.implode(',', Rfq::STATUSES)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
