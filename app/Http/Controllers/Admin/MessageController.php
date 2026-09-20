<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrivateMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Private messages between two users — the "Message" button on a person's
 * hover card, and the Messages pages where they're read and answered. A
 * message is only ever visible to its sender and its recipient; nobody,
 * Admin included, reads anyone else's.
 */
class MessageController extends Controller
{
    /**
     * Everyone the signed-in user has talked to, most recent first, each with
     * the last thing said and how many of theirs are still unread.
     */
    public function index(Request $request): View
    {
        $me = $request->user();

        // The newest message in each conversation, whichever way it went.
        $latestIds = PrivateMessage::query()
            ->where(fn ($query) => $query->where('sender_id', $me->id)->orWhere('recipient_id', $me->id))
            ->groupByRaw('case when sender_id = ? then recipient_id else sender_id end', [$me->id])
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return view('admin.messages.index', [
            'conversations' => PrivateMessage::query()
                ->with(['sender.roles', 'recipient.roles'])
                ->whereIn('id', $latestIds)
                ->latest('id')
                ->get(),
            'unread' => $me->receivedMessages()->unread()->groupBy('sender_id')->selectRaw('sender_id, count(*) as total')->pluck('total', 'sender_id'),
        ]);
    }

    /**
     * One conversation, oldest first. Opening it reads what the other person
     * has said; what the signed-in user said stays as it was.
     */
    public function show(Request $request, User $user): View|RedirectResponse
    {
        $me = $request->user();

        if ($user->is($me)) {
            return redirect()->route('admin.messages.index');
        }

        $user->load('roles');

        $me->receivedMessages()->unread()->where('sender_id', $user->id)->update(['read_at' => now()]);

        return view('admin.messages.show', [
            'other' => $user,
            'messages' => PrivateMessage::query()->between($me, $user)->oldest('id')->get(),
        ]);
    }

    /**
     * Sends a message. From a hover card it arrives as a fetch() and gets
     * JSON back, so it can say "Sent" without leaving the page (or the modal
     * the card was opened from); from anywhere else it's a plain form post.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $me = $request->user();

        $validator = Validator::make($request->all(), [
            'recipient_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$me->id])],
            'body' => ['required', 'string', 'max:'.PrivateMessage::MAX_LENGTH],
        ], [
            'recipient_id.not_in' => 'You can\'t send a message to yourself.',
            'body.required' => 'Write a message to send.',
            'body.max' => 'That message is too long — keep it under '.PrivateMessage::MAX_LENGTH.' characters.',
        ]);

        // Answered by hand: this app only renders JSON errors for api/*, so a
        // fetch()'s failed validation would otherwise come back as a redirect.
        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422)
                : back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        $recipient = User::findOrFail($validated['recipient_id']);

        $me->sentMessages()->create([
            'recipient_id' => $recipient->id,
            'body' => trim($validated['body']),
        ]);

        $conversation = route('admin.messages.show', $recipient);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Message sent to {$recipient->name}.",
                'conversation' => $conversation,
            ], 201);
        }

        return redirect($conversation)->with('status', "Message sent to {$recipient->name}.");
    }
}
