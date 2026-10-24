<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqCommentAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
function commenter(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * $user posts $body on $rfq — a reply to $parent, if given — with $photos.
 *
 * @param  array<int, UploadedFile>  $photos
 */
function postComment(User $user, Rfq $rfq, string $body, ?RfqComment $parent = null, array $photos = []): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.show', $rfq))
        ->post(route('admin.rfqs.comments.store', $rfq), array_filter(['body' => $body, 'parent_id' => $parent?->id, 'attachments' => $photos]));
}

/**
 * Who was sent $comment, by id.
 *
 * @return array<int, int>
 */
function toldOf(RfqComment $comment): array
{
    return PrivateMessage::query()->where('rfq_comment_id', $comment->id)->orderBy('recipient_id')->pluck('recipient_id')->all();
}

/**
 * RFQ8401 ("Pumps"), created by Ben (Business Development), routed by Olivia
 * (Senior Operations), with Sourcing's part held by Riley, its Data Entry
 * done and its Data Entry given to Dana — and the people.
 *
 * @return array{0: Rfq, 1: array<string, User>}
 */
function rfqWithPeople(): array
{
    $people = [
        'ben' => commenter('Ben Dev', 'Business Development'),
        'olivia' => commenter('Olivia Park', 'Senior Operations'),
        'riley' => commenter('Riley Chen', 'Sourcing'),
        'dana' => commenter('Dana Entry', 'Data Entry'),
    ];

    $rfq = splitAmong(Rfq::factory()->create([
        'rfq_number' => 'RFQ8401',
        'subject' => 'Pumps',
        'created_by' => $people['ben']->id,
        'operations_assigned_by' => $people['olivia']->id,
        'operations_assigned_at' => now(),
    ]), [1 => $people['riley']]);
    $rfq->assignees()->updateExistingPivot($people['riley']->id, ['completed_at' => now(), 'line_items_to_add' => 4, 'data_entry_assigned_to' => $people['dana']->id]);

    return [$rfq, $people];
}

it('sends a comment on an RFQ to everyone involved in it, in their Messages — not its author, nor anyone else', function () {
    [$rfq, $people] = rfqWithPeople();
    $morgan = commenter('Morgan Ellis', 'General Manager');
    $rfq->comments()->create(['user_id' => $morgan->id, 'body' => 'Any update?']);
    // Not involved: another Sourcing member, another Senior Operations.
    commenter('Sam Rivera', 'Sourcing');
    commenter('Omar Ops', 'Senior Operations');

    postComment($people['riley'], $rfq, 'Supplier quotes are in.')
        ->assertRedirect(route('admin.rfqs.show', $rfq))
        ->assertSessionHas('status', 'Comment posted. Ben Dev, Olivia Park, Dana Entry and Morgan Ellis will see it in Messages.');

    $comment = RfqComment::query()->latest('id')->first();
    expect(toldOf($comment))->toBe([$people['ben']->id, $people['olivia']->id, $people['dana']->id, $morgan->id])
        ->and(PrivateMessage::query()->where('rfq_comment_id', $comment->id)->pluck('sender_id')->unique()->all())->toBe([$people['riley']->id])
        ->and(PrivateMessage::query()->where('rfq_comment_id', $comment->id)->first()->body)->toBe("Commented on RFQ8401 — Pumps:\nSupplier quotes are in.");

    // In their Messages and bell, unread, with the way back to the RFQ.
    test()->actingAs($people['ben'])->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Riley Chen: Commented on RFQ8401');
    test()->actingAs($people['ben'])->get(route('admin.messages.index'))->assertOk()
        ->assertSee('Riley Chen')
        ->assertSee('Commented on RFQ8401 — Pumps:');
    test()->actingAs($people['ben'])->get(route('admin.messages.show', $people['riley']))->assertOk()
        ->assertSee('Supplier quotes are in.')
        ->assertSee('Open RFQ8401');
});

it('names the first three and counts the rest when it goes to more than four', function () {
    [$rfq, $people] = rfqWithPeople();
    foreach (['Gail Assist', 'Hana Head'] as $name) {
        $rfq->comments()->create(['user_id' => commenter($name, 'Admin')->id, 'body' => 'Noted']);
    }

    postComment($people['riley'], $rfq, 'Quotes are in.')
        ->assertSessionHas('status', 'Comment posted. Ben Dev, Olivia Park, Dana Entry and 2 others will see it in Messages.');
});

it('sends someone it @mentions the mention only, and says a reply was one — to your comment, or a comment', function () {
    [$rfq, $people] = rfqWithPeople();
    $parent = $rfq->comments()->create(['user_id' => $people['ben']->id, 'body' => 'Customer wants it by Friday']);

    postComment($people['riley'], $rfq, '@Olivia Park can we make Friday?', $parent)
        ->assertSessionHas('status', 'Reply posted. Olivia Park, Ben Dev and Dana Entry will see it in Messages.');

    $reply = RfqComment::query()->latest('id')->first();
    expect(PrivateMessage::query()->where('rfq_comment_id', $reply->id)->pluck('body', 'recipient_id')->all())->toBe([
        $people['olivia']->id => "Mentioned you in a reply on RFQ8401 — Pumps:\n@Olivia Park can we make Friday?",
        $people['ben']->id => "Replied to your comment on RFQ8401 — Pumps:\n@Olivia Park can we make Friday?",
        $people['dana']->id => "Replied to a comment on RFQ8401 — Pumps:\n@Olivia Park can we make Friday?",
    ]);
});

it('sends it to whoever the RFQ is waiting with — Senior Operations before it\'s assigned, a review stage\'s people', function () {
    $ben = commenter('Ben Dev', 'Business Development');
    $operations = [commenter('Olivia Park', 'Senior Operations'), commenter('Omar Ops', 'Senior Operations')];
    $heads = [commenter('Hana Head', 'Head of Business Development'), commenter('Hugo Head', 'Head of Business Development')];
    $unassigned = Rfq::factory()->create(['created_by' => $ben->id]);
    $inReview = Rfq::factory()->create(['created_by' => $ben->id, 'stage' => 'head_of_bd_review', 'status' => 'Completed']);

    postComment($ben, $unassigned, 'Urgent, please.');
    expect(toldOf(RfqComment::query()->latest('id')->first()))->toBe([$operations[0]->id, $operations[1]->id]);

    postComment($ben, $inReview, 'Please approve today.');
    expect(toldOf(RfqComment::query()->latest('id')->first()))->toBe([$heads[0]->id, $heads[1]->id]);
});

it('says so on a closed RFQ, and that photos went with it', function () {
    Storage::fake(RfqCommentAttachment::DISK);
    [$rfq, $people] = rfqWithPeople();
    $rfq->forceFill(['status' => 'Completed', 'stage' => 'closed'])->save();

    postComment($people['riley'], $rfq, 'Final prices attached.', photos: [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]);

    expect(PrivateMessage::query()->where('recipient_id', $people['ben']->id)->sole()->body)
        ->toBe("Commented on closed RFQ8401 — Pumps:\nFinal prices attached.\n(2 photos attached)");
});

it('doesn\'t send it to someone involved who can\'t open the RFQ, or whose account is deactivated', function () {
    [$rfq, $people] = rfqWithPeople();
    $harper = tap(User::factory()->create(['name' => 'Harper Hr']))->assignRole(Role::findOrCreate('HR Executive'));
    $rfq->comments()->create(['user_id' => $harper->id, 'body' => 'Checking attendance for this job']);
    $people['dana']->forceFill(['deactivated_at' => now()])->save();
    // Another Business Development member, who sees only their own RFQs.
    $rfq->comments()->create(['user_id' => commenter('Bea Other', 'Business Development')->id, 'body' => 'Wrong RFQ?']);

    postComment($people['riley'], $rfq, 'Quotes are in.')
        ->assertSessionHas('status', 'Comment posted. Ben Dev and Olivia Park will see it in Messages.');

    expect(toldOf(RfqComment::query()->latest('id')->first()))->toBe([$people['ben']->id, $people['olivia']->id]);
});

it('tells the commenter, in the reply box too, that everyone on the RFQ gets it', function () {
    [$rfq, $people] = rfqWithPeople();
    $rfq->comments()->create(['user_id' => $people['ben']->id, 'body' => 'Customer called']);

    test()->actingAs($people['olivia'])->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('Write a comment... (everyone on this RFQ gets it in Messages — @ to mention someone else)', false)
        ->assertSee('Write a reply... (everyone on this RFQ gets it in Messages — @ to mention someone else)', false);
});
