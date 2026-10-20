<?php

namespace App;

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Tells people in their Messages (and so their bell, and a pop-up) of work
 * that's just come to them by name: a part assigned to them, given to them
 * in place of someone else, sent to them to finalize, or sent back to them
 * with a reason — each a private message from whoever did it, saying what
 * and on which RFQ, with any note that went with it, and leading back to
 * the RFQ (private_messages.rfq_id). Sent by the Rfq's workflow methods as
 * they act (Rfq::assignSourcingParts(), completeSourcingPart(),
 * completeDataEntryPart(), returnSourcingPart(), returnToDataEntry(),
 * rejectToStage(), rejectPartToStage(), replaceSourcingPart(),
 * forwardBack()). Never to whoever did it, nor to a deactivated account.
 */
final class TaskMessages
{
    /**
     * Sends each of $to a message from $from: "$headline — subject", then
     * $note on its own line if there is one. Linked to the RFQ, and to the
     * comment the note was posted as, if any. Returns who was sent one.
     *
     * @param  iterable<int, User|null>  $to
     * @return Collection<int, User>
     */
    public static function send(Rfq $rfq, ?User $from, iterable $to, string $headline, ?string $note = null, ?RfqComment $comment = null): Collection
    {
        if ($from === null) {
            return collect();
        }

        $recipients = collect($to)
            ->filter(fn (?User $user) => $user !== null && ! $user->is($from) && ! $user->isDeactivated())
            ->unique('id')
            ->values();

        $note = trim((string) $note);
        $body = "{$headline} — {$rfq->subject}".($note !== '' ? ":\n{$note}" : '.');

        foreach ($recipients as $user) {
            PrivateMessage::query()->create([
                'sender_id' => $from->id,
                'recipient_id' => $user->id,
                'rfq_id' => $rfq->id,
                'rfq_comment_id' => $comment?->id,
                'body' => mb_substr($body, 0, PrivateMessage::MAX_LENGTH),
            ]);
        }

        return $recipients;
    }
}
