<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
    Role::findOrCreate('Data Entry');
});

/**
 * An RFQ Sourcing has finished, so it's on Data Entry's list — with a comment
 * from each of the given users on its thread.
 *
 * @param  array<int, User>  $commenters
 */
function rfqWithComments(array $commenters, User $sourcing): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $sourcing]);
    $rfq->completeSourcingPart(1);

    foreach ($commenters as $commenter) {
        $rfq->comments()->create(['user_id' => $commenter->id, 'body' => "A word from {$commenter->name}"]);
    }

    return $rfq->refresh();
}

// ---- sending ---------------------------------------------------------------

it('sends a private message and says so', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Data Entry')];

    test()->actingAs($sam)
        ->postJson(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => '  Can you check the quote?  '])
        ->assertCreated()
        ->assertJson([
            'message' => "Message sent to {$riley->name}.",
            'conversation' => route('admin.messages.show', $riley),
        ]);

    $message = PrivateMessage::sole();

    expect($message->sender_id)->toBe($sam->id)
        ->and($message->recipient_id)->toBe($riley->id)
        ->and($message->body)->toBe('Can you check the quote?')
        ->and($message->read_at)->toBeNull();
});

it('sends from a plain form too, landing on the conversation', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Data Entry')];

    test()->actingAs($sam)
        ->post(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => 'Hello'])
        ->assertRedirect(route('admin.messages.show', $riley))
        ->assertSessionHas('status', "Message sent to {$riley->name}.");

    expect(PrivateMessage::count())->toBe(1);
});

it('turns down a message with nothing to say, too much, or nobody to say it to — as JSON for a fetch', function (array $payload, string $field) {
    $riley = userWithRole('Sourcing');
    $sam = userWithRole('Data Entry');

    $payload = array_map(fn ($value) => $value === 'RILEY' ? $riley->id : $value, $payload);

    test()->actingAs($sam)
        ->postJson(route('admin.messages.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(PrivateMessage::count())->toBe(0);
})->with([
    'no body' => [['recipient_id' => 'RILEY'], 'body'],
    'an empty body' => [['recipient_id' => 'RILEY', 'body' => ''], 'body'],
    'only spaces' => [['recipient_id' => 'RILEY', 'body' => '   '], 'body'],
    'too long' => [['recipient_id' => 'RILEY', 'body' => str_repeat('a', 2001)], 'body'],
    'no recipient' => [['body' => 'Hello'], 'recipient_id'],
    'someone who does not exist' => [['recipient_id' => 999999, 'body' => 'Hello'], 'recipient_id'],
]);

it('sends a plain form back with what was wrong', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Data Entry')];

    test()->actingAs($sam)
        ->from(route('admin.messages.show', $riley))
        ->post(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => ''])
        ->assertRedirect(route('admin.messages.show', $riley))
        ->assertSessionHasErrors(['body' => 'Write a message to send.']);

    expect(PrivateMessage::count())->toBe(0);
});

it('will not let anyone message themselves', function () {
    $sam = userWithRole('Data Entry');

    test()->actingAs($sam)
        ->postJson(route('admin.messages.store'), ['recipient_id' => $sam->id, 'body' => 'Note to self'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipient_id');

    expect(PrivateMessage::count())->toBe(0);
});

it('needs someone signed in', function () {
    $riley = userWithRole('Sourcing');

    // (This app sends guests to the login page whatever they asked for.)
    test()->postJson(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => 'Hello'])->assertRedirect(route('login'));
    test()->get(route('admin.messages.index'))->assertRedirect(route('login'));
    test()->get(route('admin.messages.show', $riley))->assertRedirect(route('login'));

    expect(PrivateMessage::count())->toBe(0);
});

// ---- the inbox -------------------------------------------------------------

it('lists a person\'s conversations, latest first, with what was said last and what\'s unread', function () {
    [$me, $riley, $sam, $priya] = [userWithRole('Data Entry'), userWithRole('Sourcing'), userWithRole('Sourcing'), userWithRole('Sourcing')];

    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $me->id, 'body' => 'First from Riley']);
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $me->id, 'body' => 'Second from Riley']);
    PrivateMessage::factory()->create(['sender_id' => $me->id, 'recipient_id' => $sam->id, 'body' => 'My note to Sam']);
    PrivateMessage::factory()->create(['sender_id' => $priya->id, 'recipient_id' => $me->id, 'body' => 'Latest from Priya']);
    // Someone else's conversation altogether.
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $sam->id, 'body' => 'Between Riley and Sam']);

    $response = test()->actingAs($me)->get(route('admin.messages.index'))->assertOk();

    $response->assertSeeInOrder(['Latest from Priya', 'My note to Sam', 'Second from Riley'])
        ->assertSee('You:')
        ->assertDontSee('First from Riley')
        ->assertDontSee('Between Riley and Sam')
        ->assertSee('Sourcing')
        // Riley's two are unread.
        ->assertSee('title="2 unread"', false);
});

it('says so when there are no messages yet', function () {
    test()->actingAs(userWithRole('Data Entry'))->get(route('admin.messages.index'))
        ->assertOk()
        ->assertSee('No messages yet');
});

// ---- a conversation --------------------------------------------------------

it('shows a conversation oldest first and reads what the other person said', function () {
    [$me, $riley] = [userWithRole('Data Entry'), userWithRole('Sourcing')];

    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $me->id, 'body' => 'One from Riley']);
    PrivateMessage::factory()->create(['sender_id' => $me->id, 'recipient_id' => $riley->id, 'body' => 'Two from me']);
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $me->id, 'body' => 'Three from Riley']);
    // Something Riley said to someone else — not part of it.
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => userWithRole('Sourcing')->id, 'body' => 'Not for me']);

    test()->actingAs($me)->get(route('admin.messages.show', $riley))
        ->assertOk()
        ->assertSeeInOrder(['One from Riley', 'Two from me', 'Three from Riley'])
        ->assertDontSee('Not for me')
        ->assertSee($riley->email)
        ->assertSee('Sourcing');

    expect(PrivateMessage::where('recipient_id', $me->id)->whereNull('read_at')->count())->toBe(0)
        // What I said hasn't been read by Riley just because I looked.
        ->and(PrivateMessage::where('sender_id', $me->id)->sole()->read_at)->toBeNull()
        // And Riley's message to someone else stays unread.
        ->and(PrivateMessage::where('body', 'Not for me')->sole()->read_at)->toBeNull();
});

it('keeps a conversation to the two people in it, Admin included', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $sam->id, 'body' => 'A private matter']);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.messages.show', $riley))
        ->assertOk()
        ->assertDontSee('A private matter');

    expect(PrivateMessage::sole()->read_at)->toBeNull();
});

it('sends a person to their inbox rather than a conversation with themselves', function () {
    $me = userWithRole('Data Entry');

    test()->actingAs($me)->get(route('admin.messages.show', $me))->assertRedirect(route('admin.messages.index'));
    test()->actingAs($me)->get('/admin/messages/999999')->assertNotFound();
});

// ---- the unread count on the sidebar ---------------------------------------

it('shows the unread count on the Messages link, and only theirs', function () {
    [$me, $riley] = [userWithRole('Data Entry'), userWithRole('Sourcing')];
    PrivateMessage::factory()->count(3)->create(['sender_id' => $riley->id, 'recipient_id' => $me->id]);
    PrivateMessage::factory()->read()->create(['sender_id' => $riley->id, 'recipient_id' => $me->id]);
    PrivateMessage::factory()->create(['sender_id' => $me->id, 'recipient_id' => $riley->id]);

    test()->actingAs($me)->get(route('admin.dashboard'))->assertSee('title="3 unread"', false);
    test()->actingAs($riley)->get(route('admin.dashboard'))->assertSee('title="1 unread"', false);

    // Nothing unread, no badge.
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))
        ->assertSee('Messages')
        ->assertDontSee(' unread"', false);
});

// ---- names on comments -----------------------------------------------------

it('shows each commenter\'s role beside their name, with a card of their details behind it', function () {
    [$riley, $dataEntry] = [userWithRole('Sourcing'), userWithRole('Data Entry')];
    $rfq = rfqWithComments([$riley], $riley);

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    expect($html)->toContain('<span class="badge badge-soft-secondary rfq-comment-role">Sourcing</span>')
        ->toContain('data-user-id="'.$riley->id.'"')
        ->toContain('data-name="'.e($riley->name).'"')
        ->toContain('data-email="'.e($riley->email).'"')
        ->toContain('data-roles="Sourcing"')
        ->toContain('data-since="'.$riley->created_at->format('M Y').'"')
        ->toContain('data-self="0"')
        ->toContain('data-conversation="'.route('admin.messages.show', $riley).'"');
});

it('shows it on Sourcing\'s modal as well, for a reply too, and knows which name is theirs', function () {
    [$riley, $dataEntry] = [userWithRole('Sourcing'), userWithRole('Data Entry')];
    $rfq = rfqWithComments([$dataEntry], $riley);
    $rfq->comments()->create(['user_id' => $riley->id, 'body' => 'A reply from Riley', 'parent_id' => $rfq->comments()->first()->id]);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    expect($html)->toContain('<span class="badge badge-soft-secondary rfq-comment-role">Data Entry</span>')
        ->toContain('data-user-id="'.$dataEntry->id.'"')
        ->toContain('data-self="0"')
        // Their own name gets a card too — that just doesn't offer to message them.
        ->toContain('data-user-id="'.$riley->id.'"')
        ->toContain('data-self="1"');
});

it('says "Deleted user" without a card when the commenter\'s account is gone', function () {
    $riley = userWithRole('Sourcing');
    $commenter = userWithRole('Data Entry');
    $rfq = rfqWithComments([$commenter], $riley);
    $commenter->delete();

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    expect($html)->toContain('Deleted user')
        ->not->toContain('data-user-id="'.$commenter->id.'"');
});

it('puts the same card on names in the RFQ\'s own comments', function () {
    $riley = userWithRole('Sourcing');
    $rfq = rfqWithComments([$riley], $riley);

    test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.show', $rfq))
        ->assertOk()
        ->assertSee('js-user-card', false)
        ->assertSee('data-user-id="'.$riley->id.'"', false)
        ->assertSee('rfq-comment-role', false);
});

it('carries the hover card and its message box on every page', function () {
    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('id="userCard"', false)
        ->assertSee('action="'.route('admin.messages.store').'"', false)
        ->assertSee('Private message')
        ->assertSee('Only you and', false);
});

it('loads the commenters\' roles in one go, however many there are', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    // The signed-in user's own roles load on their first request; measure after that.
    test()->actingAs($dataEntry)->get(route('admin.dashboard'));

    $roleQueries = function (int $commenters) use ($riley, $dataEntry) {
        $rfq = rfqWithComments(collect(range(1, $commenters))->map(fn () => userWithRole('Senior Operations'))->all(), $riley);

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'model_has_roles'))->count();
        DB::disableQueryLog();
        $rfq->delete();

        return $count;
    };

    expect($roleQueries(6))->toBe($roleQueries(1));
});
