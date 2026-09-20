<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
    Role::findOrCreate('Data Entry');
});

function completeAs($user, Rfq $rfq, array $payload)
{
    return test()->actingAs($user)->patch(route('admin.rfqs.complete-sourcing', $rfq), $payload);
}

it('asks for a comment before a part can be completed', function (array $payload) {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    completeAs($riley, $rfq, ['part' => 1] + $payload)
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
})->with([
    'no comment' => [[]],
    'an empty one' => [['comment' => '']],
    'only spaces' => [['comment' => '   ']],
    'far too long' => [['comment' => str_repeat('a', 2001)]],
]);

it('says what is missing when the comment is', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    completeAs($riley, $rfq, ['part' => 1])
        ->assertSessionHas('error', 'Add a comment to mark this part complete.');

    completeAs($riley, $rfq, ['part' => 1, 'comment' => str_repeat('a', 2001)])
        ->assertSessionHas('error', 'That comment is too long — keep it under 2000 characters.');
});

it('completes the part and posts the comment as theirs', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    completeAs($riley, $rfq, ['part' => 1, 'comment' => '  Three quotes attached.  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $comment = $rfq->refresh()->comments()->sole();

    expect($rfq->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($comment->user_id)->toBe($riley->id)
        // What they wrote, marked as a completion — the thread says so beside their name.
        ->and($comment->body)->toBe('Three quotes attached.')
        ->and($comment->action)->toBe('sourcing_completed')
        ->and($comment->meta)->toBeNull()
        ->and($comment->parent_id)->toBeNull();
});

it('names the part when the RFQ is split, since the thread covers the whole RFQ', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), [1 => $riley, 2 => $riley, 3 => $riley]);

    completeAs($riley, $rfq, ['part' => 2, 'comment' => 'Prices confirmed.'])->assertSessionHas('status');

    $comment = $rfq->refresh()->comments()->sole();

    expect($comment->body)->toBe('Prices confirmed.')
        ->and($comment->action)->toBe('sourcing_completed')
        ->and($comment->meta)->toBe(['part' => 2, 'label' => 'RFQ1001-P2 of P3']);
});

it('posts nothing a second time for a part that is already complete', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $riley]);

    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'First']);
    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'Again']);

    expect($rfq->refresh()->comments()->count())->toBe(1);
});

it('takes a new comment each time a returned part is completed again', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'First go']);
    $rfq->refresh()->returnSourcingPart(1, 'Missing prices', $dataEntry);
    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'Prices added']);

    $thread = $rfq->refresh()->comments()->get();

    expect($thread->pluck('body')->all())->toBe(['First go', 'Missing prices', 'Prices added'])
        ->and($thread->pluck('action')->all())->toBe(['sourcing_completed', 'returned_to_sourcing', 'sourcing_completed']);
});

it('still only lets the part\'s own assignee complete it — comment or not', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    completeAs($sam, $rfq, ['part' => 1, 'comment' => 'Not mine'])->assertForbidden();
    completeAs($sam, $rfq, ['part' => 1])->assertForbidden();
    completeAs(userWithRole('Data Entry'), $rfq, ['part' => 1, 'comment' => 'Not theirs either'])->assertForbidden();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
});

it('goes back to the list, the RFQ or the dashboard it was done from', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $riley, 3 => $riley]);

    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'One', 'redirect_status' => 'Pending'])
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));
    completeAs($riley, $rfq, ['part' => 2, 'comment' => 'Two', 'return_to' => 'show'])
        ->assertRedirect(route('admin.rfqs.show', $rfq));
    completeAs($riley, $rfq, ['part' => 3, 'comment' => 'Three', 'return_to' => 'dashboard'])
        ->assertRedirect(route('admin.dashboard'));
});

it('gives Sourcing a Mark Complete that asks, and no comment box of their own, on My Pending RFQs', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), [1 => $riley, 2 => $riley]);
    $rfq->comments()->create(['user_id' => userWithRole('Senior Operations')->id, 'body' => 'Please be quick on this one']);

    $response = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk();
    $html = $response->getContent();

    // The thread can still be read…
    $response->assertSee('Please be quick on this one');

    // …but there's nothing to post to it from here.
    expect($html)->not->toContain(route('admin.rfqs.comments.store', $rfq))
        ->not->toContain('Post comment')
        ->not->toContain('Write a comment...');

    // Every Mark Complete opens the one prompt: a row's, and the modal's, which comes back to it.
    expect($html)->toContain('id="completeModal"')
        ->toContain('name="comment"')
        ->and(substr_count($html, 'js-complete"'))->toBe(4)
        ->and(substr_count($html, 'data-redirect-status="Pending"'))->toBe(4)
        ->and($html)->toContain('data-back-modal="rfq-detail-modal-'.$rfq->id.'-p1"')
        ->toContain('data-back-modal="rfq-detail-modal-'.$rfq->id.'-p2"');
    // …and nothing completes without going through it.
    expect($html)->not->toContain('data-confirm="Mark this part of the split RFQ complete');
});

it('offers nothing to complete on a part that is already done', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(), [1 => $riley])->completeSourcingPart(1);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();

    expect($html)->not->toContain('js-complete"');
});

it('asks for the comment on the RFQ\'s own page too', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), [1 => $riley, 2 => $riley]);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect($html)->toContain('data-return-to="show"')
        ->toContain('Mark P1 Complete')
        ->toContain('Mark P2 Complete')
        ->toContain('id="completeModal"');

    // With nothing left to complete there's nothing to ask.
    completeAs($riley, $rfq, ['part' => 1, 'comment' => 'One']);
    completeAs($riley, $rfq, ['part' => 2, 'comment' => 'Two']);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->getContent();
    expect($html)->not->toContain('js-complete"')->not->toContain('id="completeModal"');
});
