<?php

use App\Models\Rfq;
use App\Models\RfqComment;
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
    Role::findOrCreate('Head of Business Development');
});

/**
 * A three-way split every part of which Sourcing has completed, with the
 * comments that went with it — a completion, and Data Entry sending part 2
 * back — plus an ordinary comment from Senior Operations. Returns the RFQ and
 * the people.
 *
 * @return array{0: Rfq, 1: User, 2: User, 3: User}
 */
function rfqWithActionComments(): array
{
    [$riley, $dataEntry, $ops] = [userWithRole('Sourcing'), userWithRole('Data Entry'), userWithRole('Senior Operations')];

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'priority_level' => 'Medium']), [1 => $riley, 2 => $riley, 3 => $riley]);
    $rfq->comments()->create(['user_id' => $ops->id, 'body' => 'Please be quick on this one']);

    // A moment between each, so the thread has an order to it.
    test()->travel(1)->minutes();
    $rfq->refresh()->completeSourcingPart(1, 'Three quotes attached');
    test()->travel(1)->minutes();
    $rfq->refresh()->completeSourcingPart(2, 'Supplier confirmed stock');
    $rfq->refresh()->completeSourcingPart(3);
    test()->travel(1)->minutes();
    $rfq->refresh()->returnSourcingPart(2, 'Quote is missing prices', $dataEntry);

    return [$rfq->refresh(), $riley, $dataEntry, $ops];
}

/**
 * One part's quick-detail modal, lifted out of the list page's HTML.
 */
function partModal(string $html, Rfq $rfq, int $part): string
{
    $start = strpos($html, 'id="rfq-detail-modal-'.$rfq->id.'-p'.$part.'"');
    $next = strpos($html, 'class="modal fade"', $start + 1);

    return substr($html, $start, $next === false ? null : $next - $start);
}

it('knows how each action reads, looks and is toned', function (string $action, string $label, string $icon, string $tone) {
    $comment = new RfqComment(['action' => $action]);

    expect($comment->actionLabel())->toBe($label)
        ->and($comment->actionIcon())->toBe($icon)
        ->and($comment->actionTone())->toBe($tone);
})->with([
    'a completion' => ['sourcing_completed', 'Marked complete', 'bi-check-circle-fill', 'success'],
    'Data Entry\'s completion' => ['data_entry_completed', 'Completed in Data Entry', 'bi-check2-circle', 'success'],
    'a return' => ['returned_to_sourcing', 'Returned to Sourcing', 'bi-arrow-counterclockwise', 'danger'],
    'a rejection' => ['rejected', 'Rejected', 'bi-x-octagon-fill', 'danger'],
]);

it('has no action, and a speech bubble, for an ordinary comment', function () {
    $comment = new RfqComment(['body' => 'Hello']);

    expect($comment->actionLabel())->toBeNull()
        ->and($comment->actionIcon())->toBe('bi-chat-left-text')
        ->and($comment->actionTone())->toBe('primary')
        ->and($comment->actionContext())->toBeNull();
});

it('says what an action was about', function (?array $meta, ?string $context) {
    expect((new RfqComment(['action' => 'returned_to_sourcing', 'meta' => $meta]))->actionContext())->toBe($context);
})->with([
    'nothing' => [null, null],
    'a part of a split' => [['part' => 2, 'label' => 'RFQ1001-P2 of P3'], 'RFQ1001-P2 of P3'],
    'someone\'s part' => [['who' => 'Sam Rivera'], 'Sam Rivera\'s part'],
    'someone\'s part of a split' => [['who' => 'Sam Rivera', 'label' => 'RFQ1001-P2 of P3'], 'Sam Rivera\'s part · RFQ1001-P2 of P3'],
    'where it was sent back to' => [['stage' => 'Sourcing'], 'Returned to Sourcing'],
]);

it('belongs on the thread of the part it was recorded against, or of every part when it was not', function (?array $meta, int $part, bool $belongs) {
    expect((new RfqComment(['action' => 'returned_to_sourcing', 'meta' => $meta]))->concernsPart($part))->toBe($belongs);
})->with([
    'nothing recorded' => [null, 2, true],
    'the RFQ as a whole' => [['stage' => 'Sourcing'], 1, true],
    'this part' => [['part' => 2, 'label' => 'RFQ1001-P2 of P3'], 2, true],
    'another part' => [['part' => 2, 'label' => 'RFQ1001-P2 of P3'], 1, false],
]);

it('marks a Head of Business Development rejection with where it went', function () {
    $riley = userWithRole('Sourcing');
    $head = userWithRole('Head of Business Development');
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'head_of_bd_review']), [1 => $riley]);

    $rfq->rejectToStage('sourcing', 'The quotes do not add up', $head);

    // Sending it back to Sourcing returns each part too; the rejection is its own comment.
    $comment = $rfq->refresh()->comments()->where('action', 'rejected')->sole();

    expect($comment->body)->toBe('The quotes do not add up')
        ->and($comment->user_id)->toBe($head->id)
        ->and($comment->meta)->toBe(['stage' => 'Sourcing'])
        ->and($comment->actionContext())->toBe('Returned to Sourcing')
        ->and($rfq->comments()->pluck('action')->all())->toBe(['returned_to_sourcing', 'rejected']);
});

it('reads the action back out of comments written before it was recorded', function () {
    $rfq = Rfq::factory()->create();
    $migration = require database_path('migrations/2026_09_19_220301_add_action_to_rfq_comments_table.php');

    // Back to how they were: the action written into the text.
    $migration->down();

    $legacy = [
        'Marked complete: Three quotes attached',
        'Marked RFQ1001-P2 of P3 complete: Prices confirmed',
        'Marked complete in Data Entry: All entered',
        'Marked RFQ1001-P1 of P3 complete in Data Entry: Part one entered',
        'Sent Sam Rivera\'s part back to Sourcing: Missing prices',
        'Sent Sam Rivera\'s part (RFQ1001-P2 of P3) back to Sourcing: Wrong model',
        'Head of Business Development rejected — returned to Data Entry: Please re-check',
        'Just an ordinary comment: with a colon in it',
    ];
    foreach ($legacy as $body) {
        DB::table('rfq_comments')->insert(['rfq_id' => $rfq->id, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
    }

    $migration->up();

    $comments = RfqComment::query()->orderBy('id')->get()->map(fn (RfqComment $comment) => [$comment->action, $comment->body, $comment->meta])->all();

    expect($comments)->toBe([
        ['sourcing_completed', 'Three quotes attached', null],
        ['sourcing_completed', 'Prices confirmed', ['label' => 'RFQ1001-P2 of P3', 'part' => 2]],
        ['data_entry_completed', 'All entered', null],
        ['data_entry_completed', 'Part one entered', ['label' => 'RFQ1001-P1 of P3', 'part' => 1]],
        ['returned_to_sourcing', 'Missing prices', ['who' => 'Sam Rivera']],
        ['returned_to_sourcing', 'Wrong model', ['label' => 'RFQ1001-P2 of P3', 'part' => 2, 'who' => 'Sam Rivera']],
        ['rejected', 'Please re-check', ['stage' => 'Data Entry']],
        [null, 'Just an ordinary comment: with a colon in it', null],
    ]);

    // And rolling it back puts the text back, so nothing is lost either way.
    $migration->down();

    expect(DB::table('rfq_comments')->orderBy('id')->pluck('body')->all())->toBe($legacy);

    $migration->up();
});

it('shows the thread in the modal as a vertical timeline, each comment with what it recorded', function () {
    [$rfq, $riley, $dataEntry] = rfqWithActionComments();

    // Part 2 was sent back; Sourcing has done it again.
    $rfq->refresh()->completeSourcingPart(2, 'Re-sent with unit prices');

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    // Part 2's modal: the thread is one timeline…
    $modal = partModal($html, $rfq, 2);

    expect($modal)->toContain('rfq-timeline rfq-thread')
        // …its stops numbered so they animate in one after another…
        ->toContain('style="--i: 0"')
        ->toContain('style="--i: 1"')
        // …an ordinary comment on a plain marker…
        ->toContain('rfq-timeline-item rfq-timeline-item-primary rfq-thread-item')
        ->toContain('Please be quick on this one')
        // …the completion beside its author's name, with which part…
        ->toContain('rfq-timeline-item rfq-timeline-item-success rfq-thread-item')
        ->toContain('<i class="bi bi-check-circle-fill"></i> Marked complete')
        ->toContain('<span class="comment-action-context">RFQ1001-P2 of P3</span>')
        ->toContain('Supplier confirmed stock')
        // …and the return, with whose part.
        ->toContain('rfq-timeline-item rfq-timeline-item-danger rfq-thread-item')
        ->toContain('<i class="bi bi-arrow-counterclockwise"></i> Returned to Sourcing')
        ->toContain('<span class="comment-action-context">'.e("{$riley->name}'s part · RFQ1001-P2 of P3").'</span>')
        ->toContain('Quote is missing prices')
        ->toContain('Re-sent with unit prices')
        // The words themselves aren't written into the text any more.
        ->not->toContain('Marked RFQ1001-P2 of P3 complete:')
        ->not->toContain('back to Sourcing:');
});

it('keeps each part of a split to its own thread, with what concerns the whole RFQ on all of them', function () {
    [$rfq, , $dataEntry] = rfqWithActionComments();
    $rfq->refresh()->completeSourcingPart(2, 'Re-sent with unit prices');
    $rfq->comments()->create([
        'user_id' => userWithRole('Head of Business Development')->id,
        'body' => 'Please re-check every line',
        'action' => 'rejected',
        'meta' => ['stage' => 'Data Entry'],
    ]);

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    [$one, $two, $three] = array_map(fn (int $part) => partModal($html, $rfq, $part), [1, 2, 3]);

    // Each part has its own completion and, for part 2, its return…
    expect($one)->toContain('Three quotes attached')
        ->not->toContain('Supplier confirmed stock')
        ->not->toContain('Quote is missing prices')
        ->not->toContain('Re-sent with unit prices');
    expect($two)->toContain('Supplier confirmed stock')
        ->toContain('Quote is missing prices')
        ->toContain('Re-sent with unit prices')
        ->not->toContain('Three quotes attached');
    expect($three)->not->toContain('Three quotes attached')
        ->not->toContain('Supplier confirmed stock')
        ->not->toContain('Quote is missing prices');

    // …while an ordinary comment and a rejection of the whole RFQ are on each.
    foreach ([$one, $two, $three] as $modal) {
        expect($modal)->toContain('Please be quick on this one')
            ->toContain('<i class="bi bi-x-octagon-fill"></i> Rejected')
            ->toContain('Please re-check every line');
    }
});

it('shows the same on Sourcing\'s modal, in the order it happened', function () {
    [$rfq, $riley] = rfqWithActionComments();

    // Part 2 was sent back, so it's worked from Sourcing's Returns list.
    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()->getContent();

    $modal = partModal($html, $rfq, 2);

    expect($modal)->toContain('Marked complete')
        ->toContain('Returned to Sourcing')
        // Part 1's completion is on part 1's modal, not this one.
        ->not->toContain('Three quotes attached');

    // Oldest first, down the line — from the thread on, since the return
    // reason is also noted up by the assignee.
    $thread = substr($modal, strpos($modal, 'rfq-timeline rfq-thread'));

    expect(strpos($thread, 'Please be quick on this one'))->toBeLessThan(strpos($thread, 'Supplier confirmed stock'))
        ->and(strpos($thread, 'Supplier confirmed stock'))->toBeLessThan(strpos($thread, 'Quote is missing prices'));
});

it('keeps an ordinary comment plain, and its replies under it', function () {
    [$rfq, $riley, $dataEntry, $ops] = rfqWithActionComments();
    $first = $rfq->comments()->whereNull('action')->first();
    $rfq->comments()->create(['user_id' => $riley->id, 'parent_id' => $first->id, 'body' => 'On it — quotes by noon']);

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();

    expect($html)->toContain('rfq-thread-replies')
        ->toContain('On it — quotes by noon')
        // Each name is still a hover card, with its role beside it.
        ->toContain('data-user-id="'.$ops->id.'"')
        ->toContain('<span class="badge badge-soft-secondary rfq-comment-role">Senior Operations</span>');
});

it('says so when there are no comments yet', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->completeSourcingPart(1);

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();

    expect($html)->toContain('No comments yet.')->not->toContain('rfq-timeline rfq-thread');
});

it('puts the action beside the name on the RFQ\'s own timeline too, with a marker to match', function () {
    [$rfq, , $dataEntry] = rfqWithActionComments();

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect($html)->toContain('<i class="bi bi-check-circle-fill"></i> Marked complete')
        ->toContain('<i class="bi bi-arrow-counterclockwise"></i> Returned to Sourcing')
        ->toContain('comment-action comment-action-success')
        ->toContain('comment-action comment-action-danger')
        ->toContain('Please be quick on this one');
});
