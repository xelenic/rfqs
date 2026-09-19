<?php

namespace App\Models;

use Database\Factories\RfqPartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned Sourcing part of a split RFQ — "P3 of P5" — and who, if
 * anyone, holds it. The same person can hold several parts of one RFQ;
 * they're worked and completed together as that person's single share
 * (see rfq_user / Rfq::assignees()). See Rfq::sourcingParts().
 */
class RfqPart extends Model
{
    /** @use HasFactory<RfqPartFactory> */
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'part_number',
        'user_id',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
