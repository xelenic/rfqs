<?php

namespace App\Models;

use Database\Factories\GroupMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a group chat (MessageGroup) — who said it (null once their
 * account's gone), what, and when. Its members read it in Messages → Groups.
 */
class GroupMessage extends Model
{
    /** @use HasFactory<GroupMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'message_group_id',
        'user_id',
        'body',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(MessageGroup::class, 'message_group_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
