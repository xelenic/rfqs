<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupMessage;
use App\Models\MessageGroup;
use App\Models\PrivateMessage;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Messages → Groups: group chats (MessageGroup). An Admin sets one up — a
 * name, what it's for, and who's in it — changes it, or deletes it; its
 * members read what's said in it and write to it, each with what's still
 * unread for them, in their bell too (User::bellItems()). Only members read
 * a group, Admin included: an Admin who isn't in one manages it without
 * reading it.
 */
class MessageGroupController extends Controller
{
    /**
     * The groups they're in — an Admin, every group — the latest said first,
     * each with its last message and what's unread for them; for an Admin,
     * New group and each one's Edit and Delete too.
     */
    public function index(Request $request): View
    {
        $me = $request->user();
        $isAdmin = $me->hasRole('Admin');
        $mine = $me->messageGroups()->pluck('message_groups.id')->all();

        $groups = MessageGroup::query()
            ->when(! $isAdmin, fn ($query) => $query->whereKey($mine))
            ->with(['members' => fn ($members) => $members->with('roles')->orderBy('name')])
            ->withCount('members')
            ->addSelect(['last_message_id' => GroupMessage::query()->select('id')->whereColumn('group_messages.message_group_id', 'message_groups.id')->latest('id')->limit(1)])
            ->get()
            ->sortByDesc(fn (MessageGroup $group) => [$group->last_message_id ?? 0, $group->created_at->getTimestamp()])
            ->values();

        return view('admin.messages.groups', [
            'groups' => $groups,
            'mine' => $mine,
            // What's last said — only in the groups they're in.
            'lastMessages' => GroupMessage::query()->with('sender')
                ->whereIn('id', $groups->filter(fn (MessageGroup $group) => in_array($group->id, $mine, true))->pluck('last_message_id')->filter())
                ->get()
                ->keyBy('id'),
            'unread' => MessageGroup::unreadCountsFor($me),
            'isAdmin' => $isAdmin,
            'people' => $isAdmin ? User::query()->active()->with('roles')->orderBy('name')->get() : collect(),
        ]);
    }

    /**
     * Sets up a group: a name no other group has, what it's for, and who's
     * in it — the Admin too, unless they leave themselves out — at least two
     * people. Admin only. Opens it, if they're in it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $this->validated($request, 'createGroup');
        $members = $this->membersFrom($request, $validated);

        $group = DB::transaction(function () use ($request, $validated, $members) {
            $group = MessageGroup::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $group->setMembers($members);

            return $group;
        });

        return (in_array($request->user()->id, $members, true)
            ? redirect()->route('admin.messages.group', $group)
            : redirect()->route('admin.messages.groups'))
            ->with('status', "Group \"{$group->name}\" set up with ".count($members).' people.');
    }

    /**
     * Changes a group's name, what it's for, and who's in it — those added
     * see what's been said; those taken off no longer do. Admin only.
     */
    public function update(Request $request, MessageGroup $group): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $this->validated($request, 'editGroup', $group);
        $members = $this->membersFrom($request, $validated);

        DB::transaction(function () use ($group, $validated, $members) {
            $group->update(['name' => $validated['name'], 'description' => $validated['description'] ?? null]);
            $group->setMembers($members);
        });

        return redirect()->back()->with('status', "Saved \"{$group->name}\" — ".count($members).' people in it.');
    }

    /**
     * Deletes a group and everything said in it. Admin only.
     */
    public function destroy(Request $request, MessageGroup $group): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $group->delete();

        return redirect()->route('admin.messages.groups')->with('status', "Deleted the group \"{$group->name}\".");
    }

    /**
     * A group's chat, oldest first, with who's in it and the box to write in
     * it. For its members only. Opening it reads what's unread.
     */
    public function show(Request $request, MessageGroup $group): View
    {
        $me = $request->user();

        abort_unless($group->hasMember($me), 403, 'Only the people in a group can read it.');

        $group->markReadFor($me);

        return view('admin.messages.group', [
            'group' => $group->load(['members' => fn ($members) => $members->with('roles')->orderBy('name')]),
            'messages' => $group->messages()->with('sender.roles')->get(),
            'isAdmin' => $me->hasRole('Admin'),
        ]);
    }

    /**
     * Says something in a group — for its members only — and back to it.
     */
    public function send(Request $request, MessageGroup $group): RedirectResponse
    {
        $me = $request->user();

        abort_unless($group->hasMember($me), 403, 'Only the people in a group can write in it.');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.PrivateMessage::MAX_LENGTH],
        ], [
            'body.required' => 'Write a message to send.',
            'body.max' => 'That message is too long — keep it under '.PrivateMessage::MAX_LENGTH.' characters.',
        ]);

        $group->messages()->create(['user_id' => $me->id, 'body' => trim($validated['body'])]);
        $group->markReadFor($me);

        return redirect()->route('admin.messages.group', $group);
    }

    /**
     * The name — up to 100 characters, no other group's, whatever its case —
     * what it's for, and who's in it (member ids, of active people), validated
     * into error bag $bag.
     *
     * @return array{name: string, description: ?string, members: array<int, int>, include_me?: bool}
     */
    private function validated(Request $request, string $bag, ?MessageGroup $group = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'description' => filled($request->input('description')) ? trim((string) $request->input('description')) : null,
        ]);

        return $request->validateWithBag($bag, [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, string $value, Closure $fail) use ($group) {
                    $taken = MessageGroup::query()
                        ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                        ->when($group, fn ($query) => $query->whereKeyNot($group->id))
                        ->exists();

                    if ($taken) {
                        $fail('There\'s already a group called that.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'members' => ['array'],
            'members.*' => ['integer', Rule::exists('users', 'id')->whereNull('deactivated_at')],
            'include_me' => ['boolean'],
        ], [
            'name.required' => 'Give the group a name.',
            'name.max' => 'Keep the name under 100 characters.',
            'description.max' => 'Keep what it\'s for under 255 characters.',
            'members.*.exists' => 'Pick people who are still on the system.',
        ]);
    }

    /**
     * Who's to be in the group: those picked, and the Admin themselves when
     * they keep "Include me" — at least two people, or back with why.
     *
     * @param  array{members?: array<int, int>, include_me?: bool}  $validated
     * @return array<int, int>
     */
    private function membersFrom(Request $request, array $validated): array
    {
        $members = collect($validated['members'] ?? [])->map(fn ($id) => (int) $id)
            ->when($request->boolean('include_me'), fn ($ids) => $ids->push($request->user()->id))
            ->unique()
            ->values()
            ->all();

        if (count($members) < 2) {
            $bag = $request->isMethod('post') ? 'createGroup' : 'editGroup';

            throw ValidationException::withMessages(['members' => 'A group needs at least two people in it.'])->errorBag($bag);
        }

        return $members;
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can set up and change groups.');
    }
}
