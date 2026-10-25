<?php

use App\Models\GroupMessage;
use App\Models\MessageGroup;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Someone in $role called $name.
 */
function chatter(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * The Pumps team — Ada (Admin) set it up with Riley (Sourcing) and Dana (Data
 * Entry) in it, herself too.
 *
 * @return array{group: MessageGroup, ada: User, riley: User, dana: User}
 */
function pumpsTeam(): array
{
    $ada = chatter('Ada Admin', 'Admin');
    $riley = chatter('Riley Chen', 'Sourcing');
    $dana = chatter('Dana Entry', 'Data Entry');

    test()->actingAs($ada)->post(route('admin.messages.groups.store'), [
        'name' => 'Pumps team', 'description' => 'Everyone on the pumps RFQs', 'members' => [$riley->id, $dana->id], 'include_me' => '1',
    ])->assertSessionHasNoErrors();

    return ['group' => MessageGroup::query()->sole(), 'ada' => $ada, 'riley' => $riley, 'dana' => $dana];
}

it('lets an Admin set up a group — its name, what it\'s for and who\'s in it — and opens its chat', function () {
    $ada = chatter('Ada Admin', 'Admin');
    $riley = chatter('Riley Chen', 'Sourcing');
    $dana = chatter('Dana Entry', 'Data Entry');

    test()->actingAs($ada)->get(route('admin.messages.groups'))->assertOk()
        ->assertSeeInOrder(['Private messages', 'Groups', 'RFQ comments'])
        ->assertSee('data-bs-target="#newGroupModal"', false)
        ->assertSee('No groups yet');

    test()->actingAs($ada)->post(route('admin.messages.groups.store'), [
        'name' => '  Pumps team ', 'description' => 'Everyone on the pumps RFQs', 'members' => [$riley->id, $dana->id], 'include_me' => '1',
    ])->assertRedirect(route('admin.messages.group', MessageGroup::query()->sole()))
        ->assertSessionHas('status', 'Group "Pumps team" set up with 3 people.');

    $group = MessageGroup::query()->sole();
    expect($group->name)->toBe('Pumps team')
        ->and($group->created_by)->toBe($ada->id)
        ->and($group->members()->orderBy('name')->pluck('name')->all())->toBe(['Ada Admin', 'Dana Entry', 'Riley Chen']);

    test()->actingAs($riley)->get(route('admin.messages.groups'))->assertOk()
        ->assertSee('Pumps team')
        ->assertDontSee('data-bs-target="#newGroupModal"', false);
});

it('keeps setting up and changing groups to an Admin', function () {
    ['group' => $group, 'riley' => $riley] = pumpsTeam();

    test()->actingAs($riley)->post(route('admin.messages.groups.store'), ['name' => 'Mine', 'members' => [$riley->id, userWithRole('Sourcing')->id]])->assertForbidden();
    test()->actingAs($riley)->put(route('admin.messages.groups.update', $group), ['name' => 'Renamed', 'members' => [$riley->id]])->assertForbidden();
    test()->actingAs($riley)->delete(route('admin.messages.groups.destroy', $group))->assertForbidden();
});

it('refuses a group without a name, with one another group has, or with fewer than two people — back in the popup', function (Closure $payload, string $message) {
    ['ada' => $ada, 'riley' => $riley] = pumpsTeam();

    test()->actingAs($ada)->from(route('admin.messages.groups'))
        ->post(route('admin.messages.groups.store'), $payload($riley))
        ->assertRedirect(route('admin.messages.groups'));

    test()->actingAs($ada)->get(route('admin.messages.groups'))->assertOk()
        ->assertSee($message)
        ->assertSee("getElementById('newGroupModal')", false);

    expect(MessageGroup::query()->count())->toBe(1);
})->with([
    'no name' => [fn (User $riley) => ['name' => '', 'members' => [$riley->id], 'include_me' => '1'], 'Give the group a name.'],
    'a name taken' => [fn (User $riley) => ['name' => 'PUMPS TEAM', 'members' => [$riley->id], 'include_me' => '1'], 'There\'s already a group called that.'],
    'only one person' => [fn (User $riley) => ['name' => 'Just me', 'members' => [], 'include_me' => '1'], 'A group needs at least two people in it.'],
]);

it('lets its members talk — each sees what others said as unread, in Groups, the bell and Messages\' count, till they open it', function () {
    ['group' => $group, 'ada' => $ada, 'riley' => $riley, 'dana' => $dana] = pumpsTeam();

    test()->actingAs($riley)->post(route('admin.messages.groups.send', $group), ['body' => '  Supplier quotes are in.  '])
        ->assertRedirect(route('admin.messages.group', $group));
    test()->actingAs($ada)->post(route('admin.messages.groups.send', $group), ['body' => 'Great — Dana, over to you.']);

    expect(GroupMessage::query()->pluck('body')->all())->toBe(['Supplier quotes are in.', 'Great — Dana, over to you.'])
        ->and(MessageGroup::unreadCountsFor($dana)->all())->toBe([$group->id => 2])
        ->and(MessageGroup::unreadCountsFor($riley)->all())->toBe([$group->id => 1])
        ->and($dana->unreadMessageCount())->toBe(2);

    $html = test()->actingAs($dana)->get(route('admin.messages.groups'))->assertOk()
        ->assertSee('Ada Admin:')
        ->assertSee('Great — Dana, over to you.')
        ->getContent();
    expect(Str::betweenFirst($html, 'data-group="'.$group->id.'"', '</form>'))->not->toContain('Edit')
        ->and(collect($dana->bellItems()['items'])->firstWhere('icon', 'bi-people'))
        ->toMatchArray(['unread' => true, 'text' => 'Pumps team · Ada Admin: Great — Dana, over to you.', 'note' => '2 new in the group', 'url' => route('admin.messages.group', $group)]);

    test()->actingAs($dana)->get(route('admin.messages.group', $group))->assertOk()
        ->assertSeeInOrder(['Supplier quotes are in.', 'Great — Dana, over to you.'])
        ->assertSee('Riley Chen')
        ->assertSee('action="'.route('admin.messages.groups.send', $group).'"', false);

    expect(MessageGroup::unreadCountsFor($dana)->all())->toBe([])
        ->and($dana->bellItems()['unread'])->toBe(0);
});

it('keeps a group to its members — an Admin outside it manages it without reading it', function () {
    ['group' => $group, 'riley' => $riley] = pumpsTeam();
    $outsider = chatter('Sam Rivera', 'Sourcing');
    $otherAdmin = chatter('Avery Admin', 'Admin');
    GroupMessage::factory()->create(['message_group_id' => $group->id, 'user_id' => $riley->id, 'body' => 'Team only']);

    test()->actingAs($outsider)->get(route('admin.messages.group', $group))->assertForbidden();
    test()->actingAs($outsider)->post(route('admin.messages.groups.send', $group), ['body' => 'Let me in'])->assertForbidden();
    test()->actingAs($outsider)->get(route('admin.messages.groups'))->assertOk()->assertDontSee('Pumps team');

    test()->actingAs($otherAdmin)->get(route('admin.messages.group', $group))->assertForbidden();
    test()->actingAs($otherAdmin)->get(route('admin.messages.groups'))->assertOk()
        ->assertSee('Pumps team')
        ->assertSee('You\'re not in it', false)
        ->assertDontSee('Team only')
        ->assertSee('id="editGroupModal-'.$group->id.'"', false);
});

it('renames a group and changes who\'s in it — those added see the history, not a pile of unread; those taken off lose it', function () {
    ['group' => $group, 'ada' => $ada, 'riley' => $riley, 'dana' => $dana] = pumpsTeam();
    GroupMessage::factory()->create(['message_group_id' => $group->id, 'user_id' => $riley->id, 'body' => 'Before Sam']);
    $sam = chatter('Sam Rivera', 'Sourcing');

    test()->actingAs($ada)->from(route('admin.messages.groups'))->put(route('admin.messages.groups.update', $group), [
        'group_id' => $group->id, 'name' => 'Pumps & valves team', 'description' => '', 'members' => [$ada->id, $riley->id, $sam->id],
    ])->assertRedirect(route('admin.messages.groups'))
        ->assertSessionHas('status', 'Saved "Pumps & valves team" — 3 people in it.');

    expect($group->refresh()->name)->toBe('Pumps & valves team')
        ->and($group->description)->toBeNull()
        ->and($group->members()->orderBy('name')->pluck('name')->all())->toBe(['Ada Admin', 'Riley Chen', 'Sam Rivera'])
        ->and(MessageGroup::unreadCountsFor($sam)->all())->toBe([]);

    test()->actingAs($sam)->get(route('admin.messages.group', $group))->assertOk()->assertSee('Before Sam');
    test()->actingAs($dana)->get(route('admin.messages.group', $group))->assertForbidden();
});

it('opens a group\'s Edit from its chat, and reopens it saying what\'s wrong', function () {
    ['group' => $group, 'ada' => $ada] = pumpsTeam();

    test()->actingAs($ada)->get(route('admin.messages.group', $group))->assertOk()
        ->assertSee('href="'.route('admin.messages.groups', ['edit' => $group->id]).'"', false);
    test()->actingAs($ada)->get(route('admin.messages.groups', ['edit' => $group->id]))->assertOk()
        ->assertSee("getElementById('editGroupModal-{$group->id}')", false);

    test()->actingAs($ada)->from(route('admin.messages.groups'))->put(route('admin.messages.groups.update', $group), ['group_id' => $group->id, 'name' => 'Pumps team', 'members' => [$ada->id]]);
    test()->actingAs($ada)->get(route('admin.messages.groups'))->assertOk()
        ->assertSee('A group needs at least two people in it.')
        ->assertSee("getElementById('editGroupModal-{$group->id}')", false);
});

it('deletes a group and everything said in it', function () {
    ['group' => $group, 'ada' => $ada, 'riley' => $riley] = pumpsTeam();
    GroupMessage::factory()->count(2)->create(['message_group_id' => $group->id, 'user_id' => $riley->id]);

    test()->actingAs($ada)->delete(route('admin.messages.groups.destroy', $group))
        ->assertRedirect(route('admin.messages.groups'))
        ->assertSessionHas('status', 'Deleted the group "Pumps team".');

    expect(MessageGroup::query()->count())->toBe(0)->and(GroupMessage::query()->count())->toBe(0);
});

it('marks a group read with the bell\'s Mark all as read', function () {
    ['group' => $group, 'riley' => $riley, 'dana' => $dana] = pumpsTeam();
    GroupMessage::factory()->create(['message_group_id' => $group->id, 'user_id' => $riley->id]);

    test()->actingAs($dana)->post(route('admin.notifications.read-all'));

    expect(MessageGroup::unreadCountsFor($dana)->all())->toBe([]);
});

it('isn\'t held back by Settings → Messaging — an Admin chose who\'s in it', function () {
    Setting::putMessageRoles(['Sourcing' => ['Data Entry']]);
    ['group' => $group, 'riley' => $riley] = pumpsTeam();

    test()->actingAs($riley)->post(route('admin.messages.groups.send', $group), ['body' => 'Hi everyone, Admin included'])
        ->assertSessionHasNoErrors();

    expect(GroupMessage::query()->sole()->body)->toBe('Hi everyone, Admin included');
});
