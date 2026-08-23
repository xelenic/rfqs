<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class RfqController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:rfqs.view', only: ['index', 'show']),
            new Middleware('permission:rfqs.create', only: ['store']),
            new Middleware('permission:rfqs.edit', only: ['update', 'assign', 'assignOperations', 'completeSourcing', 'returnSourcing', 'completeDataEntry']),
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

        // Operations' Pending list is just their actionable backlog — RFQs
        // nobody's assigned to Sourcing yet — not every Pending RFQ in the
        // company regardless of stage. Matches the red count badge in the
        // sidebar (layouts/app.blade.php).
        $scopedToUnassigned = $status === 'Pending' && $request->user()->hasRole('Operations');

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
            ->with(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy'])
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
            ->when($scopedToUnassigned, fn ($query) => $query->doesntHave('assignees'))
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
                ->with(['assignees', 'comments.author', 'comments.replies.author'])
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
                ->with(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy'])
                ->has('assignees')
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'assigned_page')
                ->withQueryString()
            : null;

        return view('admin.rfqs.index', [
            'rfqs' => $rfqs,
            'bySourcingRfqs' => $bySourcingRfqs,
            'assignedRfqs' => $assignedRfqs,
            'search' => $search,
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'scopedToMe' => $scopedToMe,
            'scopedToReturns' => $scopedToReturns,
            'scopedToDataEntry' => $scopedToDataEntry,
            'scopedToUnassigned' => $scopedToUnassigned,
            'sourcingUsers' => User::role('Sourcing')->orderBy('name')->get(),
            'operationsUsers' => User::role('Operations')->orderBy('name')->get(),
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
            'rfq' => $rfq->load(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'dataEntryCompletedBy', 'comments.author.roles', 'comments.replies.author.roles']),
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'sourcingUsers' => User::role('Sourcing')->orderBy('name')->get(),
            'operationsUsers' => User::role('Operations')->orderBy('name')->get(),
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
     * Assign (or reassign) which Sourcing-team members are working this
     * RFQ. Optional — an empty selection clears all assignees. Only users
     * who actually hold the Sourcing role are ever synced, even if the
     * request is tampered with. Sourcing itself never has access to this —
     * it's a receiving role, not an assigning one.
     *
     * An Operations member assigning Sourcing directly is implicitly the
     * one routing this RFQ — if nobody's recorded as the Operations
     * assignee yet, record them, so Operations doesn't need a separate
     * "Assign Operations" step just to name themselves.
     */
    public function assign(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');

        $validated = $request->validate([
            'users' => ['array'],
            'users.*' => ['integer'],
        ]);

        $sourcingUserIds = User::role('Sourcing')->pluck('id');
        $userIds = collect($validated['users'] ?? [])->intersect($sourcingUserIds)->all();

        $rfq->assignees()->sync($userIds);

        if ($request->user()->hasRole('Operations') && ! $rfq->operations_assigned_by) {
            $rfq->update([
                'operations_assigned_by' => $request->user()->id,
                'operations_assigned_at' => now(),
            ]);
        }

        return $this->redirectAfterSave($request, $rfq)->with('status', 'RFQ assignment updated.');
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
            && User::role('Operations')->whereKey($operationsUserId)->exists();

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
