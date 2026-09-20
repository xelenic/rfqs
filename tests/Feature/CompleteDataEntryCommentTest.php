<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
    Role::findOrCreate('Business Development');
});

/**
 * An RFQ split among the given Sourcing members, every part completed by
 * Sourcing — ready for Data Entry.
 *
 * @param  array<int, User>  $holders
 */
function readyForDataEntry(array $holders, array $attributes = []): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create($attributes + ['priority_level' => 'Medium']), $holders);

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
    }

    return $rfq->refresh();
}

function dataEntryCompletes($user, Rfq $rfq, array $payload)
{
    return test()->actingAs($user)->patch(route('admin.rfqs.complete-data-entry', $rfq), $payload);
}

it('asks Data Entry for a comment before a part can be completed', function (array $payload) {
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing')]);

    dataEntryCompletes(userWithRole('Data Entry'), $rfq, ['part' => 1] + $payload)
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
})->with([
    'no comment' => [[]],
    'an empty one' => [['comment' => '']],
    'only spaces' => [['comment' => '   ']],
    'far too long' => [['comment' => str_repeat('a', 2001)]],
]);

it('completes the part and posts the comment as Data Entry\'s', function () {
    $dataEntry = userWithRole('Data Entry');
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing')]);

    dataEntryCompletes($dataEntry, $rfq, ['part' => 1, 'comment' => '  All prices entered and checked.  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $comment = $rfq->refresh()->comments()->sole();

    expect($rfq->assigneeForPart(1)->pivot->data_entry_completed_at)->not->toBeNull()
        ->and($comment->user_id)->toBe($dataEntry->id)
        // Their words as written, marked as Data Entry's completion of the Sourcing member's part.
        ->and($comment->body)->toBe('All prices entered and checked.')
        ->and($comment->action)->toBe('data_entry_completed')
        ->and($comment->meta)->toBe(['who' => $rfq->assigneeForPart(1)->name]);
});

it('names the part on a split, and moves the RFQ on to review once every part is done', function () {
    $dataEntry = userWithRole('Data Entry');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = readyForDataEntry([1 => $riley, 2 => $sam], ['rfq_number' => 'RFQ1001']);

    dataEntryCompletes($dataEntry, $rfq, ['part' => 1, 'comment' => 'First part entered']);
    expect($rfq->refresh()->stage)->toBeNull();

    dataEntryCompletes($dataEntry, $rfq, ['part' => 2, 'comment' => 'Second part entered']);

    expect($rfq->refresh()->stage)->toBe('senior_ops_review')
        ->and($rfq->comments()->pluck('body')->all())->toBe(['First part entered', 'Second part entered'])
        ->and($rfq->comments()->get()->map(fn ($comment) => $comment->meta['label'] ?? null)->all())->toBe(['RFQ1001-P1 of P2', 'RFQ1001-P2 of P2'])
        ->and($rfq->comments()->pluck('action')->unique()->all())->toBe(['data_entry_completed']);
});

it('posts nothing a second time for a part Data Entry has already completed', function () {
    $dataEntry = userWithRole('Data Entry');
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    dataEntryCompletes($dataEntry, $rfq, ['part' => 1, 'comment' => 'First']);
    dataEntryCompletes($dataEntry, $rfq, ['part' => 1, 'comment' => 'Again']);

    expect($rfq->refresh()->comments()->count())->toBe(1);
});

it('lets only Data Entry or Admin complete a part, with or without a comment', function () {
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing')]);

    foreach (['Sourcing', 'Business Development'] as $role) {
        dataEntryCompletes(userWithRole($role), $rfq, ['part' => 1, 'comment' => 'Not mine'])->assertForbidden();
        dataEntryCompletes(userWithRole($role), $rfq, ['part' => 1])->assertForbidden();
    }

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);

    // Admin can — and is asked for the same comment.
    $admin = userWithRole('Admin');
    dataEntryCompletes($admin, $rfq, ['part' => 1])->assertSessionHas('error');
    dataEntryCompletes($admin, $rfq, ['part' => 1, 'comment' => 'Entered by Admin'])->assertSessionHas('status');

    expect($rfq->refresh()->comments()->sole()->user_id)->toBe($admin->id);
});

it('goes back to the page it was done from', function () {
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing')]);

    test()->actingAs(userWithRole('Data Entry'))
        ->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Done'])
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));
});

it('gives Data Entry a Mark Complete that asks, and no comment box of their own, on Ready for Data Entry', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = readyForDataEntry([1 => $riley, 2 => $sam], ['rfq_number' => 'RFQ1001']);
    $rfq->comments()->create(['user_id' => $riley->id, 'body' => 'Quotes attached to the RFQ']);

    $response = test()->actingAs(userWithRole('Data Entry'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk();
    $html = $response->getContent();

    // The thread can still be read…
    $response->assertSee('Quotes attached to the RFQ');

    // …but there's nothing to post to it from here, or to complete without going through the prompt.
    expect($html)->not->toContain(route('admin.rfqs.comments.store', $rfq))
        ->not->toContain('Post comment')
        ->not->toContain('Write a comment...')
        ->not->toContain('data-confirm="Mark ')
        ->toContain('id="completeModal"');

    // Mark Complete and Return to Sourcing for each part, on its row and in its modal — which Back returns to.
    expect(substr_count($html, 'js-complete"'))->toBe(8)
        ->and(substr_count($html, 'data-action="'.route('admin.rfqs.complete-data-entry', $rfq).'"'))->toBe(4)
        ->and(substr_count($html, 'data-action="'.route('admin.rfqs.return-sourcing', $rfq).'"'))->toBe(4)
        ->and($html)->toContain('data-who="'.e($riley->name).'"')
        ->toContain('data-who="'.e($sam->name).'"')
        ->toContain('data-audience="Senior Operations"')
        ->toContain('data-back-modal="rfq-detail-modal-'.$rfq->id.'-p1"')
        ->toContain('data-back-modal="rfq-detail-modal-'.$rfq->id.'-p2"')
        // They can reach the whole RFQ to reply.
        ->toContain('Open full RFQ');
});

it('words each prompt for whoever is next in line', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    // Sourcing completes to hand on to Data Entry…
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertSee('data-audience="Data Entry"', false)
        ->assertDontSee('data-audience="Senior Operations"', false);

    // …and Data Entry to hand on to Senior Operations.
    $rfq->refresh()->completeSourcingPart(1);
    test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertSee('data-audience="Senior Operations"', false)
        ->assertDontSee('data-audience="Data Entry"', false);
});

it('lets Sourcing read what Data Entry said on completing', function () {
    $riley = userWithRole('Sourcing');
    $rfq = readyForDataEntry([1 => $riley, 2 => $riley]);
    dataEntryCompletes(userWithRole('Data Entry'), $rfq, ['part' => 1, 'comment' => 'Prices entered']);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee('Prices entered');
});

it('asks on the RFQ\'s own page too, leaving the comment box there', function () {
    $riley = userWithRole('Sourcing');
    $rfq = readyForDataEntry([1 => $riley, 2 => userWithRole('Sourcing')], ['rfq_number' => 'RFQ1001']);
    $dataEntry = userWithRole('Data Entry');

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    // Each part has its Return to Sourcing beside its Mark Complete, and the RFQ's own comment box stays.
    expect(substr_count($html, 'js-complete"'))->toBe(4)
        ->and($html)->toContain('id="completeModal"')
        ->toContain('data-audience="Senior Operations"')
        ->toContain('data-kind="return"')
        ->toContain('data-kind="data_entry"')
        ->not->toContain('data-confirm="Mark '.$riley->name)
        ->not->toContain('Send back to')
        ->toContain(route('admin.rfqs.comments.store', $rfq));

    // With nothing left to complete there's nothing to ask.
    dataEntryCompletes($dataEntry, $rfq, ['part' => 1, 'comment' => 'One']);
    dataEntryCompletes($dataEntry, $rfq, ['part' => 2, 'comment' => 'Two']);

    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.show', $rfq))->getContent();
    expect($html)->not->toContain('js-complete"')->not->toContain('id="completeModal"');
});

it('shows Admin the same on Data Entry\'s page', function () {
    $rfq = readyForDataEntry([1 => userWithRole('Sourcing')]);
    Role::findOrCreate('Data Entry');

    $html = test()->actingAs(userWithRole('Admin'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'data-entry']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="completeModal"')
        ->toContain('data-action="'.route('admin.rfqs.complete-data-entry', $rfq).'"')
        ->not->toContain('Post comment');
});
