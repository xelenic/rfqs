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
 * A split RFQ whose every part Sourcing has completed — ready for Data
 * Entry, and so for sending back.
 *
 * @param  array<int, User>  $holders
 * @param  array<string, mixed>  $attributes
 */
function completedBySourcing(array $holders, array $attributes = []): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create($attributes + ['priority_level' => 'Medium']), $holders);

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
    }

    return $rfq->refresh();
}

function sendsBack($user, Rfq $rfq, array $payload)
{
    return test()->actingAs($user)->patch(route('admin.rfqs.return-sourcing', $rfq), $payload);
}

it('asks Data Entry for a reason before a part is sent back', function (array $payload) {
    $rfq = completedBySourcing([1 => userWithRole('Sourcing')]);

    sendsBack(userWithRole('Data Entry'), $rfq, ['part' => 1] + $payload)
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->returned_at)->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
})->with([
    'no reason' => [[]],
    'an empty one' => [['reason' => '']],
    'only spaces' => [['reason' => '   ']],
    'far too long' => [['reason' => str_repeat('a', 1001)]],
]);

it('says what is missing when the reason is', function () {
    $rfq = completedBySourcing([1 => userWithRole('Sourcing')]);
    $dataEntry = userWithRole('Data Entry');

    sendsBack($dataEntry, $rfq, ['part' => 1])
        ->assertSessionHas('error', 'Add a reason to send this part back.');

    sendsBack($dataEntry, $rfq, ['part' => 1, 'reason' => str_repeat('a', 1001)])
        ->assertSessionHas('error', 'That comment is too long — keep it under 1000 characters.');
});

it('sends the part back with the reason, and posts it to the thread', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');
    $rfq = completedBySourcing([1 => $riley, 2 => userWithRole('Sourcing')], ['rfq_number' => 'RFQ1001']);

    sendsBack($dataEntry, $rfq, ['part' => 1, 'reason' => 'Supplier quote is missing prices'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($pivot->returned_at)->not->toBeNull()
        ->and($pivot->return_reason)->toBe('Supplier quote is missing prices')
        ->and($pivot->completed_at)->toBeNull()
        // Only that part — the other stays as it was.
        ->and($rfq->assigneeForPart(2)->pivot->returned_at)->toBeNull()
        ->and($rfq->comments()->sole()->user_id)->toBe($dataEntry->id)
        // The reason as written, marked as a return — with whose part and which.
        ->and($rfq->comments()->sole()->body)->toBe('Supplier quote is missing prices')
        ->and($rfq->comments()->sole()->action)->toBe('returned_to_sourcing')
        ->and($rfq->comments()->sole()->meta)->toBe(['part' => 1, 'label' => 'RFQ1001-P1 of P2', 'who' => $riley->name]);
});

it('lets only Data Entry or Admin send a part back', function () {
    $rfq = completedBySourcing([1 => userWithRole('Sourcing')]);

    foreach (['Sourcing', 'Business Development'] as $role) {
        sendsBack(userWithRole($role), $rfq, ['part' => 1, 'reason' => 'Not mine to say'])->assertForbidden();
    }

    sendsBack(userWithRole('Admin'), $rfq, ['part' => 1, 'reason' => 'Sent back by Admin'])->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->return_reason)->toBe('Sent back by Admin');
});

it('goes back to the page it was done from', function () {
    $rfq = completedBySourcing([1 => userWithRole('Sourcing')]);

    test()->actingAs(userWithRole('Data Entry'))
        ->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 1, 'reason' => 'Prices missing'])
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));
});

it('puts Return to Sourcing beside Mark Complete on every part, each asking for a comment', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = completedBySourcing([1 => $riley, 2 => $sam]);

    $html = test()->actingAs(userWithRole('Data Entry'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->getContent();

    $returnRoute = route('admin.rfqs.return-sourcing', $rfq);

    // Both buttons, for both parts, on their rows and in their modals — through the one prompt.
    expect(substr_count($html, 'data-kind="return"'))->toBe(4)
        ->and(substr_count($html, 'data-kind="data_entry"'))->toBe(4)
        ->and(substr_count($html, 'data-action="'.$returnRoute.'"'))->toBe(4)
        ->and($html)->toContain('data-who="'.e($riley->name).'"')
        ->toContain('Return to Sourcing')
        // No reason box of their own any more, and no confirm dialog.
        ->not->toContain('name="reason"')
        ->not->toContain('Send back to')
        ->not->toContain('data-confirm="Send ')
        ->not->toContain('rfq-return-form');

    // In a part's modal they sit together, and Back comes back to it.
    $modalStart = strpos($html, 'id="rfq-detail-modal-'.$rfq->id.'-p1"');
    $modal = substr($html, $modalStart, strpos($html, 'id="rfq-detail-modal-'.$rfq->id.'-p2"') - $modalStart);

    expect(strpos($modal, 'data-kind="return"'))->toBeLessThan(strpos($modal, 'data-kind="data_entry"'))
        ->and($modal)->toContain('data-back-modal="rfq-detail-modal-'.$rfq->id.'-p1"');
});

it('puts them side by side on the RFQ\'s own page too', function () {
    $rfq = completedBySourcing([1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect(substr_count($html, 'data-kind="return"'))->toBe(2)
        ->and(substr_count($html, 'data-kind="data_entry"'))->toBe(2)
        ->and($html)->not->toContain('name="reason"')
        ->not->toContain('Send back to');
});

it('offers neither once Data Entry has processed the part, or to Sourcing', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');
    $rfq = completedBySourcing([1 => $riley, 2 => userWithRole('Sourcing')]);
    $rfq->completeDataEntryPart(1, $dataEntry);

    // Part 1 is done; only part 2 is left to complete or send back.
    $html = test()->actingAs($dataEntry)->get(route('admin.rfqs.show', $rfq))->getContent();
    expect(substr_count($html, 'data-kind="return"'))->toBe(1);

    // Sourcing has Mark Complete on their own parts, and nothing to send back.
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertDontSee('data-kind="return"', false);
});

it('shows a returned part\'s reason where Sourcing will see it', function () {
    $riley = userWithRole('Sourcing');
    $rfq = completedBySourcing([1 => $riley]);
    sendsBack(userWithRole('Data Entry'), $rfq, ['part' => 1, 'reason' => 'Wrong model quoted']);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))
        ->assertOk()
        ->assertSee('Wrong model quoted');
});
