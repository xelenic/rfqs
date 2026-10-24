<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use App\RfqDataEraser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
function personNamed(string $name, string $role = 'Sourcing'): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * RFQ8301 ("Valves"), closed unless $status says otherwise.
 */
function rfqForMentions(string $status = 'Completed'): Rfq
{
    return Rfq::factory()->create(['rfq_number' => 'RFQ8301', 'subject' => 'Valves', 'status' => $status]);
}

/**
 * $user comments $body on $rfq — a reply to $parent, if given.
 */
function commentMentioning(User $user, Rfq $rfq, string $body, ?RfqComment $parent = null): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.show', $rfq))
        ->post(route('admin.rfqs.comments.store', $rfq), array_filter(['body' => $body, 'parent_id' => $parent?->id]));
}

it('sends whoever a comment on a closed RFQ mentions a message from its author, and says so — saying it\'s closed', function () {
    $riley = personNamed('Riley Chen');
    $operations = userWithRole('Senior Operations');
    $rfq = rfqForMentions();

    commentMentioning($operations, $rfq, 'Client asked about the flanges — @Riley Chen can you check?')
        ->assertRedirect(route('admin.rfqs.show', $rfq))
        ->assertSessionHas('status', 'Comment posted. Riley Chen will see it in Messages.');

    $message = PrivateMessage::query()->sole();
    expect($message->sender_id)->toBe($operations->id)
        ->and($message->recipient_id)->toBe($riley->id)
        ->and($message->rfq_comment_id)->toBe($rfq->comments()->sole()->id)
        ->and($message->body)->toBe("Mentioned you on closed RFQ8301 — Valves:\nClient asked about the flanges — @Riley Chen can you check?")
        ->and($message->read_at)->toBeNull();
});

it('says it was a reply when it was', function () {
    $riley = personNamed('Riley Chen');
    $operations = userWithRole('Senior Operations');
    $rfq = rfqForMentions();
    $parent = $rfq->comments()->create(['user_id' => $operations->id, 'body' => 'Client called']);

    commentMentioning($operations, $rfq, '@Riley Chen they want a revised quote', $parent)
        ->assertSessionHas('status', 'Reply posted. Riley Chen will see it in Messages.');

    expect(PrivateMessage::query()->sole()->body)->toStartWith('Mentioned you in a reply on closed RFQ8301 — Valves:');
});

it('shows in their Messages and bell, with the way back to the RFQ', function () {
    $riley = personNamed('Riley Chen');
    $operations = personNamed('Olivia Park', 'Senior Operations');
    $rfq = rfqForMentions();

    commentMentioning($operations, $rfq, '@Riley Chen please look');

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Olivia Park: Mentioned you on closed RFQ8301');

    test()->actingAs($riley)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('Olivia Park')
        ->assertSee('Mentioned you on closed RFQ8301 — Valves:');

    test()->actingAs($riley)->get(route('admin.messages.show', $operations))->assertOk()
        ->assertSee('@Riley Chen please look')
        ->assertSee('href="'.route('admin.rfqs.show', $rfq).'"', false)
        ->assertSee('Open RFQ8301');

    expect(PrivateMessage::query()->sole()->read_at)->not->toBeNull();
});

it('sends it for a mention on an RFQ at any stage — open, on hold or cancelled too', function (string $status) {
    $riley = personNamed('Riley Chen');
    $rfq = rfqForMentions($status);

    commentMentioning(userWithRole('Senior Operations'), $rfq, '@Riley Chen please look')
        ->assertSessionHas('status', 'Comment posted. Riley Chen will see it in Messages.');

    expect(PrivateMessage::query()->sole())
        ->recipient_id->toBe($riley->id)
        ->body->toBe("Mentioned you on RFQ8301 — Valves:\n@Riley Chen please look");
})->with(['Pending', Rfq::ON_HOLD, Rfq::CANCELLED]);

it('sends everyone a comment mentions their own message, whatever their role, each in their Messages', function () {
    $author = personNamed('Olivia Park', 'Senior Operations');
    $people = collect([
        'Business Development' => 'Ben Dev',
        'Sourcing' => 'Riley Chen',
        'Data Entry' => 'Dana Entry',
        'Head of Business Development' => 'Hana Head',
        'GM Assistant' => 'Gail Assist',
        'General Manager' => 'Morgan Ellis',
        'Admin' => 'Ada Admin',
    ])->map(fn (string $name, string $role) => personNamed($name, $role));
    $hr = tap(User::factory()->create(['name' => 'Harper Hr']))->assignRole(Role::findOrCreate('HR Executive'));
    $rfq = rfqForMentions('Pending');

    commentMentioning($author, $rfq, 'Team — '.$people->map(fn (User $person) => '@'.$person->name)->join(' ').' and @Harper Hr, please read.')
        ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'Comment posted. ') && str_ends_with($status, ' will see it in Messages.'));

    $everyone = $people->push($hr);
    expect(PrivateMessage::query()->orderBy('recipient_id')->pluck('recipient_id')->all())->toBe($everyone->pluck('id')->sort()->values()->all())
        ->and(PrivateMessage::query()->where('sender_id', '!=', $author->id)->count())->toBe(0);

    foreach ($everyone as $person) {
        test()->actingAs($person)->get(route('admin.messages.index'))->assertOk()
            ->assertSee('Olivia Park')
            ->assertSee('Mentioned you on RFQ8301 — Valves:');
    }
});

it('links the RFQ in the conversation only for someone who can open it', function () {
    $author = personNamed('Olivia Park', 'Senior Operations');
    $riley = personNamed('Riley Chen');
    $ben = personNamed('Ben Dev', 'Business Development');
    $dana = personNamed('Dana Entry', 'Data Entry');
    $hr = tap(User::factory()->create(['name' => 'Harper Hr']))->assignRole(Role::findOrCreate('HR Executive'));
    $rfq = rfqForMentions('Pending');
    $rfq->forceFill(['created_by' => $author->id])->save();

    commentMentioning($author, $rfq, '@Riley Chen @Ben Dev @Dana Entry @Harper Hr please look');

    $link = 'href="'.route('admin.rfqs.show', $rfq).'"';
    test()->actingAs($riley)->get(route('admin.messages.show', $author))->assertOk()->assertSee($link, false);
    // Business Development sees only their own RFQs, Data Entry only what's been given them, HR Executive none.
    foreach ([$ben, $dana, $hr] as $person) {
        test()->actingAs($person)->get(route('admin.messages.show', $author))->assertOk()
            ->assertSee('@Riley Chen @Ben Dev @Dana Entry @Harper Hr please look')
            ->assertDontSee($link, false);
    }
    // The author can.
    test()->actingAs($author)->get(route('admin.messages.show', $riley))->assertOk()->assertSee($link, false);
});

it('sends each person mentioned one message — not the author, nor a deactivated account', function () {
    $riley = personNamed('Riley Chen');
    $sam = personNamed('Sam Rivera');
    personNamed('Jordan Lee')->forceFill(['deactivated_at' => now()])->save();
    $author = personNamed('Alex Kim');
    $rfq = rfqForMentions();

    commentMentioning($author, $rfq, '@Riley Chen and @Sam Rivera — and @Riley Chen again. cc @Jordan Lee @Alex Kim')
        ->assertSessionHas('status', 'Comment posted. Riley Chen and Sam Rivera will see it in Messages.');

    expect(PrivateMessage::query()->orderBy('recipient_id')->pluck('recipient_id')->all())->toBe([$riley->id, $sam->id]);
});

it('matches whole names only — not one inside a longer name', function () {
    $riley = personNamed('Riley Chen');
    $rileyJunior = personNamed('Riley Chen Jr');
    $rfq = rfqForMentions();

    commentMentioning(userWithRole('Senior Operations'), $rfq, 'Over to @Riley Chen Jr. Not @Riley Chenoweth either.');

    expect(PrivateMessage::query()->pluck('recipient_id')->all())->toBe([$rileyJunior->id])
        ->and($riley->receivedMessages()->count())->toBe(0);
});

it('mentions anyone on the staff, as the @mention list offers them — not a deactivated account', function () {
    $morgan = personNamed('Morgan Ellis', 'General Manager');
    personNamed('Pat Gone', 'Data Entry')->forceFill(['deactivated_at' => now()])->save();
    $rfq = rfqForMentions();

    commentMentioning(userWithRole('Senior Operations'), $rfq, '@Morgan Ellis FYI, and @Pat Gone')
        ->assertSessionHas('status', 'Comment posted. Morgan Ellis will see it in Messages.');

    expect(PrivateMessage::query()->pluck('recipient_id')->all())->toBe([$morgan->id]);
});

it('takes the message away when the comment is deleted', function () {
    personNamed('Riley Chen');
    $operations = userWithRole('Senior Operations');
    $rfq = rfqForMentions();
    commentMentioning($operations, $rfq, '@Riley Chen please look');

    test()->actingAs($operations)->delete(route('admin.rfqs.comments.destroy', [$rfq, $rfq->comments()->sole()]))
        ->assertSessionHas('status', 'Comment deleted.');

    expect(PrivateMessage::query()->count())->toBe(0);
});

it('clears mention messages with the RFQ data, keeping messages written in Messages', function () {
    $riley = personNamed('Riley Chen');
    $operations = userWithRole('Senior Operations');
    commentMentioning($operations, rfqForMentions(), '@Riley Chen please look');
    $written = $operations->sentMessages()->create(['recipient_id' => $riley->id, 'body' => 'Lunch?']);

    RfqDataEraser::clear();

    expect(PrivateMessage::query()->pluck('id')->all())->toBe([$written->id]);
});

it('tells the commenter, in the comment box, that a mention goes to Messages — on any RFQ', function () {
    $operations = userWithRole('Senior Operations');

    foreach ([rfqForMentions(), Rfq::factory()->create()] as $rfq) {
        test()->actingAs($operations)->get(route('admin.rfqs.show', $rfq))->assertOk()
            ->assertSee('Write a comment... (everyone on this RFQ gets it in Messages — @ to mention someone else)', false);
    }
});

it('sends it to everyone of that name when two share it', function () {
    $riley = personNamed('Riley Chen');
    $otherRiley = personNamed('Riley Chen');
    $rfq = rfqForMentions();

    commentMentioning(userWithRole('Senior Operations'), $rfq, '@Riley Chen please look')
        ->assertSessionHas('status', 'Comment posted. Riley Chen and Riley Chen will see it in Messages.');

    expect(PrivateMessage::query()->orderBy('recipient_id')->pluck('recipient_id')->all())->toBe([$riley->id, $otherRiley->id]);
});
