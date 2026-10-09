<?php

namespace App\Models;

use Database\Factories\RfqReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part sent back by a reviewer (Rfq::rejectToStage() — a whole RFQ's
 * parts share a batch — or rejectPartToStage()): from which stage, to which,
 * by whom, and what the part (part_before — its rfq_user row) and the RFQ
 * (rfq_before — its workflow columns) were like just before. While the part
 * is still where it was sent, whoever it went to can send it straight back
 * to the reviewer, skipping the steps in between — their earlier work comes
 * back as it was (Rfq::forwardBack()). forwarded_at says it has been.
 */
class RfqReturn extends Model
{
    /** @use HasFactory<RfqReturnFactory> */
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'batch',
        'part_number',
        'user_id',
        'whole',
        'from_stage',
        'target_stage',
        'returned_by',
        'part_before',
        'rfq_before',
        'forwarded_at',
        'forwarded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'whole' => 'boolean',
            'part_before' => 'array',
            'rfq_before' => 'array',
            'forwarded_at' => 'datetime',
        ];
    }

    /**
     * The steps sending it straight back skips — strictly between where it
     * was sent and who sent it, in workflow order (Rfq::FORWARD_BACK_ORDER).
     * Nothing when it was sent back just one step, where the usual action
     * already goes straight back.
     *
     * @return array<int, string>
     */
    public function skippedStages(): array
    {
        return Rfq::stagesBetween($this->target_stage, $this->from_stage);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * Whoever sent it back.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Whoever sent it straight back.
     */
    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }
}
