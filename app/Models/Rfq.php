<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Rfq extends Model
{
    use HasFactory;

    /**
     * The selectable priority levels, in ascending order of urgency.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    /**
     * The selectable statuses. An RFQ starts Pending and is marked
     * Completed once it's been fulfilled.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['Pending', 'Completed'];

    /**
     * Prefix, zero-padding width, and starting sequence for auto-generated
     * RFQ numbers, e.g. "RFQ1001", "RFQ1002". See nextRfqNumber().
     */
    public const RFQ_NUMBER_PREFIX = 'RFQ';

    public const RFQ_NUMBER_DIGITS = 4;

    public const RFQ_NUMBER_START = 1000;

    protected $fillable = [
        'created_by',
        'operations_assigned_by',
        'operations_assigned_at',
        'sourcing_completed_by',
        'sourcing_completed_at',
        'data_entry_completed_by',
        'data_entry_completed_at',
        'wc_number',
        'rfq_number',
        'priority_level',
        'status',
        'subject',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operations_assigned_at' => 'datetime',
            'sourcing_completed_at' => 'datetime',
            'data_entry_completed_at' => 'datetime',
        ];
    }

    /**
     * The badge class to pair with this RFQ's priority level.
     */
    public function priorityBadgeClass(): string
    {
        return match ($this->priority_level) {
            'Urgent' => 'badge-soft-danger',
            'High' => 'badge-soft-warning',
            'Low' => 'badge-soft-secondary',
            default => 'badge-soft-primary',
        };
    }

    /**
     * The badge class to pair with this RFQ's status.
     */
    public function statusBadgeClass(): string
    {
        return $this->status === 'Completed' ? 'badge-soft-success' : 'badge-soft-warning';
    }

    /**
     * Users (expected to hold the Sourcing role) assigned to work this RFQ.
     * Assignment is optional — an RFQ can have none, one, or several.
     * Ordered by assignment order (pivot id) — that order drives the
     * per-assignee split RFQ numbers, see sourcingSplitNumbers().
     *
     * Each assignee completes their own split independently — pivot
     * completed_at — rather than one click completing the whole RFQ for
     * everyone. See completeSourcingPartFor() / allSourcingPartsCompleted().
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RfqAssignment::class)
            ->withTimestamps()
            ->withPivot(['completed_at', 'returned_at', 'return_reason', 'returned_by', 'data_entry_completed_at', 'data_entry_completed_by'])
            ->orderBy('rfq_user.id');
    }

    /**
     * The user who created this RFQ. Set automatically from the
     * authenticated user on creation — never user-editable. May be null
     * for RFQs whose creator's account was later deleted.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Operations-team member who routed this RFQ onward — a single
     * assignment, set via the "Assign Operations" action. Comes before
     * Sourcing assignment in the RFQ's lifecycle.
     */
    public function operationsAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operations_assigned_by');
    }

    /**
     * Top-level discussion thread on this RFQ, oldest first. Replies live
     * under each comment's replies() relation — see RfqComment.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RfqComment::class)->whereNull('parent_id')->oldest();
    }

    /**
     * The Sourcing-team member whose Mark Complete click was the last one
     * needed — i.e. the person who completed their own split after every
     * other assignee had already completed theirs, actually handing the
     * RFQ off to Data Entry. Not "the only person who worked it": with a
     * multi-way split, everyone still had to complete their own part first.
     */
    public function sourcingCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sourcing_completed_by');
    }

    /**
     * The Data Entry member whose completion was the last one needed —
     * i.e. the person who completed their processing of the last
     * outstanding assignee's split, formally closing the RFQ out. See
     * completeDataEntryPartFor().
     */
    public function dataEntryCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_completed_by');
    }

    /**
     * Whether every Sourcing assignee has completed their own part and the
     * RFQ has actually handed off to Data Entry.
     */
    public function isWithDataEntry(): bool
    {
        return $this->sourcing_completed_at !== null;
    }

    /**
     * Whether Data Entry has formally closed this RFQ out.
     */
    public function isDataEntryCompleted(): bool
    {
        return $this->status === 'Completed';
    }

    /**
     * Whether the given user has completed their own split of this RFQ's
     * Sourcing work. False for anyone not actually assigned.
     */
    public function hasCompletedSourcingPart(User $user): bool
    {
        return $this->assignees->firstWhere('id', $user->id)?->pivot->completed_at !== null;
    }

    /**
     * Whether Data Entry has sent the given user's split back for rework
     * and they haven't completed it again yet. False for anyone not
     * actually assigned.
     */
    public function hasReturnedSourcingPart(User $user): bool
    {
        $pivot = $this->assignees->firstWhere('id', $user->id)?->pivot;

        return $pivot && $pivot->returned_at !== null && $pivot->completed_at === null;
    }

    /**
     * Whether Data Entry has finished processing the given Sourcing
     * assignee's split — independent of every other assignee's split on
     * the same RFQ. False for anyone not actually assigned.
     */
    public function hasDataEntryCompletedPart(User $user): bool
    {
        return $this->assignees->firstWhere('id', $user->id)?->pivot->data_entry_completed_at !== null;
    }

    /**
     * Whether every Sourcing assignee has completed their own part — the
     * condition that actually hands the RFQ off to Data Entry. False for
     * an RFQ with no Sourcing assignees at all (nothing to complete).
     */
    public function allSourcingPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->completed_at !== null);
    }

    /**
     * Whether every Sourcing assignee's split has been completed by Data
     * Entry — the condition that formally closes the whole RFQ out. False
     * for an RFQ with no Sourcing assignees at all.
     */
    public function allDataEntryPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->data_entry_completed_at !== null);
    }

    /**
     * Marks the given user's own split of this RFQ's Sourcing work done.
     * Idempotent — completing an already-completed part is a no-op. Once
     * every assignee has completed their part, the RFQ itself is marked
     * handed off to Data Entry, recording this as the closing completion.
     *
     * Caller is responsible for verifying $user is actually assigned.
     */
    public function completeSourcingPartFor(User $user): void
    {
        if ($this->hasCompletedSourcingPart($user)) {
            return;
        }

        $this->assignees()->updateExistingPivot($user->id, [
            'completed_at' => now(),
            'returned_at' => null,
            'return_reason' => null,
            'returned_by' => null,
        ]);
        $this->load('assignees');

        if ($this->allSourcingPartsCompleted() && ! $this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => $user->id,
                'sourcing_completed_at' => now(),
            ]);
        }
    }

    /**
     * $returnedBy sends the given Sourcing assignee's split back for
     * rework — clears their completed_at (so Mark Complete is available
     * to them again) and records why and by whom, for the activity
     * timeline. Also clears that assignee's own Data Entry completion, if
     * any — sending a split back undoes Data Entry having processed it.
     * If the RFQ had already fully handed off to Data Entry, or had
     * already been formally closed out, both are undone too, since it's
     * no longer true that every assignee is done. Only this one
     * assignee's split is affected — every other assignee's own
     * completion (Sourcing- or Data-Entry-side) is untouched.
     *
     * The reason is also posted as a regular comment from $returnedBy, so
     * it counts toward the comment total and shows with full comment
     * treatment (avatar, delete) alongside the dedicated "Returned to
     * Sourcing" activity-timeline entry — not just that entry's own
     * one-line reason text.
     *
     * Caller is responsible for verifying $user is actually assigned.
     */
    public function returnSourcingPartFor(User $user, string $reason, User $returnedBy): void
    {
        $this->assignees()->updateExistingPivot($user->id, [
            'completed_at' => null,
            'returned_at' => now(),
            'return_reason' => $reason,
            'returned_by' => $returnedBy->id,
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
        ]);
        $this->load('assignees');

        if ($this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => null,
                'sourcing_completed_at' => null,
            ]);
        }

        if ($this->isDataEntryCompleted()) {
            $this->update([
                'status' => 'Pending',
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->comments()->create([
            'user_id' => $returnedBy->id,
            'body' => "Sent {$user->name}'s part back to Sourcing: {$reason}",
        ]);
        $this->load(['comments.author', 'comments.replies.author']);
    }

    /**
     * Data Entry finishes processing the given Sourcing assignee's split —
     * independent of every other assignee's split on the same RFQ, so
     * completing one person's part never touches another's. Idempotent —
     * completing an already-completed split is a no-op. Only once every
     * assignee's split has been completed here does the RFQ as a whole
     * formally close out (status becomes Completed), recording this as
     * the closing completion.
     *
     * Caller is responsible for verifying $assignee is actually assigned.
     */
    public function completeDataEntryPartFor(User $assignee, User $completedBy): void
    {
        if ($this->hasDataEntryCompletedPart($assignee)) {
            return;
        }

        $this->assignees()->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => now(),
            'data_entry_completed_by' => $completedBy->id,
        ]);
        $this->load('assignees');

        if ($this->allDataEntryPartsCompleted()) {
            $this->update([
                'status' => 'Completed',
                'data_entry_completed_by' => $completedBy->id,
                'data_entry_completed_at' => now(),
            ]);
        }
    }

    /**
     * The full chronological activity log for this RFQ's detail page — every
     * lifecycle event (created, assigned by Operations, assigned to/completed/
     * returned per Sourcing assignee, handed off to Data Entry, each
     * assignee's split completed by Data Entry, RFQ formally closed)
     * interleaved with every comment and reply, oldest first. Purely a read
     * model over existing columns — no separate activity-log table. The
     * view decides what to blur per role; this just supplies the raw facts.
     *
     * Each entry: type, at (Carbon), actor (who performed it / comment
     * author), related (the Sourcing assignee it concerns, for the
     * per-assignee types — same as actor except on sourcing_returned and
     * data_entry_part_completed, where actor is who returned/completed it
     * and related is whose part it was), detail (split number, or the
     * return reason), and comment (the RfqComment itself, only for type
     * comment).
     *
     * @return array<int, array{
     *     type: string,
     *     at: Carbon,
     *     actor: ?User,
     *     related: ?User,
     *     detail: ?string,
     *     comment: ?RfqComment,
     * }>
     */
    public function activityTimeline(): array
    {
        $entries = [
            [
                'type' => 'created',
                'at' => $this->created_at,
                'actor' => $this->creator,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ],
        ];

        if ($this->operationsAssignee && $this->operations_assigned_at) {
            $entries[] = [
                'type' => 'operations_assigned',
                'at' => $this->operations_assigned_at,
                'actor' => $this->operationsAssignee,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        foreach ($this->assignees as $assignee) {
            $entries[] = [
                'type' => 'sourcing_assigned',
                'at' => $assignee->pivot->created_at,
                'actor' => $assignee,
                'related' => $assignee,
                'detail' => $this->sourcingSplitNumberFor($assignee),
                'comment' => null,
            ];

            if ($assignee->pivot->completed_at) {
                $entries[] = [
                    'type' => 'sourcing_completed',
                    'at' => $assignee->pivot->completed_at,
                    'actor' => $assignee,
                    'related' => $assignee,
                    'detail' => $this->sourcingSplitNumberFor($assignee),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->returned_at) {
                $entries[] = [
                    'type' => 'sourcing_returned',
                    'at' => $assignee->pivot->returned_at,
                    'actor' => $assignee->pivot->returnedBy,
                    'related' => $assignee,
                    'detail' => $assignee->pivot->return_reason,
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->data_entry_completed_at) {
                $entries[] = [
                    'type' => 'data_entry_part_completed',
                    'at' => $assignee->pivot->data_entry_completed_at,
                    'actor' => $assignee->pivot->dataEntryCompletedBy,
                    'related' => $assignee,
                    'detail' => $this->sourcingSplitNumberFor($assignee),
                    'comment' => null,
                ];
            }
        }

        if ($this->sourcing_completed_at) {
            $entries[] = [
                'type' => 'sourcing_handoff',
                'at' => $this->sourcing_completed_at,
                'actor' => $this->sourcingCompletedBy,
                'related' => $this->sourcingCompletedBy,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->data_entry_completed_at) {
            $entries[] = [
                'type' => 'data_entry_closed',
                'at' => $this->data_entry_completed_at,
                'actor' => $this->dataEntryCompletedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        foreach ($this->comments as $comment) {
            $entries[] = [
                'type' => 'comment',
                'at' => $comment->created_at,
                'actor' => $comment->author,
                'related' => null,
                'detail' => null,
                'comment' => $comment,
            ];

            foreach ($comment->replies as $reply) {
                $entries[] = [
                    'type' => 'comment',
                    'at' => $reply->created_at,
                    'actor' => $reply->author,
                    'related' => null,
                    'detail' => "Reply to {$comment->author?->name}'s comment",
                    'comment' => $reply,
                ];
            }
        }

        usort($entries, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $entries;
    }

    /**
     * The next auto-generated RFQ number, e.g. "RFQ1001", "RFQ1002" — one
     * past the highest existing sequence among numbers already following
     * this format, never lower than RFQ_NUMBER_START + 1. Older/manually-
     * entered RFQ numbers (including the old "WP####" ones) don't match
     * the pattern and are ignored rather than breaking the sequence.
     */
    public static function nextRfqNumber(): string
    {
        $prefix = self::RFQ_NUMBER_PREFIX;

        $lastSequence = static::query()
            ->where('rfq_number', 'like', "{$prefix}%")
            ->pluck('rfq_number')
            ->map(function (string $rfqNumber) use ($prefix) {
                return preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $rfqNumber, $matches)
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() ?? 0;

        $nextSequence = max($lastSequence, self::RFQ_NUMBER_START) + 1;

        return $prefix.str_pad((string) $nextSequence, self::RFQ_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Split RFQ numbers per Sourcing assignee — e.g. with three assignees
     * on "RFQ1001": "RFQ1001-P1 of P3", "RFQ1001-P2 of P3",
     * "RFQ1001-P3 of P3", in assignment order. With only a single assignee
     * (or none), everyone maps to the plain rfq_number — splitting only
     * kicks in once the work is actually shared across more than one
     * person.
     *
     * @return array<int, string> user id => display RFQ number
     */
    public function sourcingSplitNumbers(): array
    {
        $assignees = $this->assignees;
        $total = $assignees->count();

        if ($total <= 1) {
            return $assignees->mapWithKeys(fn (User $assignee) => [$assignee->id => $this->rfq_number])->all();
        }

        return $assignees->values()->mapWithKeys(function (User $assignee, int $index) use ($total) {
            return [$assignee->id => "{$this->rfq_number}-P".($index + 1)." of P{$total}"];
        })->all();
    }

    /**
     * The RFQ number as it should display for one specific Sourcing
     * assignee. Falls back to the plain rfq_number for anyone not
     * actually assigned (e.g. other roles viewing the record as a whole).
     */
    public function sourcingSplitNumberFor(User $user): string
    {
        return $this->sourcingSplitNumbers()[$user->id] ?? $this->rfq_number;
    }
}
