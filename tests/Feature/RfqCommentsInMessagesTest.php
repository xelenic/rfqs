<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
function threadPerson(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * $user comments $body on $rfq, as on its page — a reply to $parent, if given.
 */
function commentOn(User $user, Rfq $rfq, string $body, ?RfqComment $parent = null): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.show', $rfq))
        ->post(route('admin.rfqs.comments.store', $rfq), array_filter(['body' => $body, 'parent_id' => $parent?->id]));
}

/**
 * RFQ8701 ("Pumps"), created by Ben, routed by Olivia, Riley's in Sourcing;
 * and RFQ8702 ("Valves"), Tara's, routed by Olivia, Sam's in Sourcing.
 *
 * @return array{pumps: Rfq, valves: Rfq, ben: User, olivia: User, riley: User}
 */
function twoDiscussions(): array
{
    $people = [
        'ben' => threadPerson('Ben Dev', 'Business Development'),
        'olivia' => threadPerson('Olivia Park', 'Senior Operations'),
        'riley' => threadPerson('Riley Chen', 'Sourcing'),
    ];

    $pumps = splitAmong(Rfq::factory()->create([
        'rfq_number' => 'RFQ8701', 'subject' => 'Pumps', 'created_by' => $people['ben']->id,
        'operations_assigned_by' => $people['olivia']->id, 'operations_assigned_at' => now(),
    ]), [1 => $people['riley']]);
    $valves = splitAmong(Rfq::factory()->create([
        'rfq_number' => 'RFQ8702', 'subject' => 'Valves', 'created_by' => threadPerson('Tara Dev', 'Business Development')->id,
        'operations_assigned_by' => $people['olivia']->id, 'operations_assigned_at' => now(),
    ]), [1 => threadPerson('Sam Rivera', 'Sourcing')]);

    return ['pumps' => $pumps, 'valves' => $valves] + $people;
}

it('lists, under RFQ comments in Messages, each RFQ discussed with them — latest first, with the latest comment and what\'s unread', function () {
    ['pumps' => $pumps, 'valves' => $valves, 'olivia' => $olivia, 'riley' => $riley] = twoDiscussions();
    test()->travel(-10)->minutes();
    commentOn($olivia, $valves, 'Valves are on hold till Monday.');
    test()->travelBack();
    commentOn($riley, $pumps, 'Supplier quotes are in.');
    commentOn($riley, $pumps, 'And the second one too.');

    $html = test()->actingAs($olivia)->get(route('admin.messages.rfqs'))->assertOk()
        ->assertSee('<title>RFQ comments', false)
        ->assertSeeInOrder(['Private messages', 'RFQ comments'])
        ->assertSeeInOrder(['RFQ8701', 'RFQ8702'])
        ->getContent();

    $pumpsRow = Str::squish(strip_tags(Str::betweenFirst($html, 'data-rfq-thread="'.$pumps->id.'"', '</a>')));
    expect($pumpsRow)->toContain('Pumps')->toContain('Riley Chen: And the second one too.')
        ->and(Str::betweenFirst($html, 'data-rfq-thread="'.$pumps->id.'"', '</a>'))->toContain('title="2 unread"')
        ->and(Str::squish(strip_tags(Str::betweenFirst($html, 'data-rfq-thread="'.$valves->id.'"', '</a>'))))->toContain('You: Valves are on hold till Monday.');

    // Riley isn't part of the Valves.
    test()->actingAs($riley)->get(route('admin.messages.rfqs'))->assertOk()
        ->assertSee('RFQ8701')->assertDontSee('RFQ8702');
});

it('opens an RFQ\'s thread — its comments and replies, not its actions — reading them, and lets them answer there', function () {
    ['pumps' => $pumps, 'olivia' => $olivia, 'riley' => $riley, 'ben' => $ben] = twoDiscussions();
    commentOn($riley, $pumps, 'Supplier quotes are in.');
    $first = RfqComment::query()->latest('id')->first();
    commentOn($ben, $pumps, 'Customer wants it by Friday.', $first);
    $pumps->comments()->create(['user_id' => $olivia->id, 'action' => 'put_on_hold', 'body' => 'Holding for drawings']);

    expect($olivia->receivedMessages()->unread()->count())->toBe(2);

    $html = test()->actingAs($olivia)->get(route('admin.messages.rfq', $pumps))->assertOk()
        ->assertSeeInOrder(['Supplier quotes are in.', 'Customer wants it by Friday.'])
        ->assertSee('To Riley Chen: “Supplier quotes are in.”', false)
        ->assertDontSee('Holding for drawings')
        ->assertSee('href="'.route('admin.rfqs.show', $pumps).'"', false)
        ->assertSee('id="rfq-mention-users"', false)
        ->assertSee('action="'.route('admin.rfqs.comments.store', $pumps).'"', false)
        ->getContent();

    expect($olivia->receivedMessages()->unread()->count())->toBe(0)
        ->and(substr_count($html, 'js-thread-reply"'))->toBe(2);

    // Answered from the thread: on the RFQ, back to the thread, and in everyone's Messages.
    test()->actingAs($olivia)->from(route('admin.messages.rfq', $pumps))
        ->post(route('admin.rfqs.comments.store', $pumps), ['body' => 'Friday works.', 'parent_id' => $first->id])
        ->assertRedirect(route('admin.messages.rfq', $pumps));

    expect($first->replies()->pluck('body')->all())->toContain('Friday works.')
        ->and($riley->receivedMessages()->latest('id')->first()->body)->toStartWith('Replied to your comment on RFQ8701');
});

it('splits Messages\' unread between private messages and RFQ comments', function () {
    ['pumps' => $pumps, 'olivia' => $olivia, 'riley' => $riley] = twoDiscussions();
    commentOn($riley, $pumps, 'Supplier quotes are in.');
    $riley->sentMessages()->create(['recipient_id' => $olivia->id, 'body' => 'Lunch?']);
    $riley->sentMessages()->create(['recipient_id' => $olivia->id, 'body' => 'At one?']);

    $tabs = Str::betweenFirst(test()->actingAs($olivia)->get(route('admin.messages.index'))->assertOk()->getContent(), 'rfq-view-toggle', '</ul>');

    expect(Str::squish(strip_tags($tabs)))->toContain('Private messages 2')->toContain('RFQ comments 1');
});

it('shows Business Development only what they wrote or were sent, with no box to post — as their RFQ page keeps the thread blurred', function () {
    ['pumps' => $pumps, 'olivia' => $olivia, 'riley' => $riley, 'ben' => $ben] = twoDiscussions();
    $pumps->comments()->create(['user_id' => $riley->id, 'body' => 'Sourcing note before Ben was told anything']);
    commentOn($olivia, $pumps, 'Ben — the customer called.');

    test()->actingAs($ben)->get(route('admin.messages.rfq', $pumps))->assertOk()
        ->assertSee('Ben — the customer called.')
        ->assertDontSee('Sourcing note before Ben was told anything')
        ->assertDontSee('id="rfqThreadForm"', false)
        ->assertSee('These are the comments you wrote or were sent.');
});

it('keeps an RFQ\'s thread from anyone who can\'t open the RFQ', function () {
    ['valves' => $valves] = twoDiscussions();
    $hr = tap(User::factory()->create())->assignRole(Role::findOrCreate('HR Executive'));

    test()->actingAs($hr)->get(route('admin.messages.rfqs'))->assertOk()->assertSee('No RFQ comments yet');
    test()->actingAs($hr)->get(route('admin.messages.rfq', $valves))->assertForbidden();
    test()->actingAs(userWithRole('Data Entry'))->get(route('admin.messages.rfq', $valves))->assertForbidden();
    test()->actingAs(threadPerson('Bea Other', 'Business Development'))->get(route('admin.messages.rfq', $valves))->assertForbidden();
});

it('leaves the RFQ comments it opens unread no more, and no other', function () {
    ['pumps' => $pumps, 'valves' => $valves, 'olivia' => $olivia, 'riley' => $riley] = twoDiscussions();
    commentOn($riley, $pumps, 'Supplier quotes are in.');
    commentOn(User::query()->where('name', 'Sam Rivera')->sole(), $valves, 'Valves quote too.');

    test()->actingAs($olivia)->get(route('admin.messages.rfq', $pumps))->assertOk();

    expect(PrivateMessage::query()->where('recipient_id', $olivia->id)->whereNull('read_at')->pluck('body')->all())
        ->toBe(["Commented on RFQ8702 — Valves:\nValves quote too."]);
});

it('shows an RFQ\'s comments once — not as a conversation with each person they went to, and one bell line for the RFQ', function () {
    ['pumps' => $pumps, 'olivia' => $olivia, 'riley' => $riley, 'ben' => $ben] = twoDiscussions();
    commentOn($riley, $pumps, 'Supplier quotes are in.');
    commentOn($olivia, $pumps, 'Great — over to Data Entry.');

    // Riley wrote to everyone on it, and was written to: no Private messages for either.
    $main = pageMain(test()->actingAs($riley)->get(route('admin.messages.index'))->assertOk());
    expect(substr_count($main, 'class="conversation-row'))->toBe(0)
        ->and($main)->toContain('No messages yet');

    // Ben has two comments on the Pumps — one line in his bell, under RFQ comments.
    $lines = collect($ben->bellItems()['items'])->where('url', route('admin.messages.rfq', $pumps));
    expect($lines)->toHaveCount(1)
        ->and($lines->first())->toMatchArray(['unread' => true, 'note' => '2 new comments', 'text' => "Olivia Park: Commented on RFQ8701 — Pumps:\nGreat — over to Data Entry."])
        ->and($ben->bellItems()['unread'])->toBe(2);

    // Read once, under RFQ comments: the bell's read too.
    test()->actingAs($ben)->get(route('admin.messages.rfq', $pumps))->assertOk();
    expect($ben->fresh()->bellItems()['unread'])->toBe(0);
});
