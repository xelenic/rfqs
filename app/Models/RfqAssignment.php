<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the rfq_user table — one row per Sourcing assignee on an
 * RFQ, tracking when they were assigned, when they completed their own
 * split of the work, whether Data Entry sent it back for rework, and
 * whether Data Entry has completed their own processing of that split. See
 * Rfq::assignees() / completeSourcingPartFor() / returnSourcingPartFor() /
 * completeDataEntryPartFor().
 */
class RfqAssignment extends Pivot
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'returned_at' => 'datetime',
            'data_entry_completed_at' => 'datetime',
        ];
    }

    /**
     * The Data Entry (or Admin) user who sent this split back for rework.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * The Data Entry (or Admin) user who completed this split's Data Entry
     * work — see Rfq::completeDataEntryPartFor().
     */
    public function dataEntryCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_completed_by');
    }
}
