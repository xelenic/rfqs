<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
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
 * An RFQ of $items items whose one part Riley has sourced and $dataEntry has
 * started on — ready to send to finalize.
 *
 * @return array{rfq: Rfq, riley: User, dataEntry: User}
 */
function startedForEntries(int $items = 20): array
{
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7001', 'number_of_items' => $items]), [1 => $riley]);
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq->refresh(), 1, $dataEntry);

    return ['rfq' => $rfq->refresh(), 'riley' => $riley, 'dataEntry' => $dataEntry];
}

/**
 * $user sends part 1 of $rfq to finalize, with $payload.
 *
 * @param  array<string, mixed>  $payload
 */
function sendToFinalizeWith(User $user, Rfq $rfq, array $payload): TestResponse
{
    return test()->actingAs($user)->patch(route('admin.rfqs.complete-data-entry', $rfq), $payload + ['part' => 1]);
}

it('asks how many entries in Send to Finalize\'s prompt, telling them how many items the RFQ has', function () {
    ['dataEntry' => $dataEntry] = startedForEntries(items: 20);

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('id="complete-entries"', false)
        ->assertSee('name="entries" id="complete-entries-input"', false)
        ->assertSee('Number of entries')
        ->assertSee('data-kind="data_entry"', false)
        ->assertSee('data-items="20"', false);
});

it('keeps the number of entries with the part, and names it on the comment', function () {
    ['rfq' => $rfq, 'dataEntry' => $dataEntry] = startedForEntries();

    sendToFinalizeWith($dataEntry, $rfq, ['entries' => '12', 'comment' => 'Prices entered'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $comment = RfqComment::where('action', 'data_entry_completed')->sole();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_entries)->toBe(12)
        ->and($comment->meta['entries'])->toBe(12)
        ->and($comment->actionContext())->toEndWith('12 entries');
});

it('takes 0 entries too, keeping and naming it', function () {
    ['rfq' => $rfq, 'dataEntry' => $dataEntry] = startedForEntries();

    sendToFinalizeWith($dataEntry, $rfq, ['entries' => '0', 'comment' => 'Nothing new to enter'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $comment = RfqComment::where('action', 'data_entry_completed')->sole();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_entries)->toBe(0)
        ->and($comment->meta['entries'])->toBe(0)
        ->and($comment->actionContext())->toEndWith('0 entries');

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'completed']))->assertOk()
        ->assertSeeInOrder(['<th class="text-end">Entries</th>', 'RFQ7001', '>0</td>'], false);
});

it('turns down a missing or wrong number of entries, sending nothing on', function (mixed $entries, string $message) {
    ['rfq' => $rfq, 'dataEntry' => $dataEntry] = startedForEntries();

    sendToFinalizeWith($dataEntry, $rfq, array_filter(['entries' => $entries, 'comment' => 'Prices entered'], fn ($value) => $value !== null))
        ->assertSessionHas('error', $message);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->toBeNull()
        ->and(RfqComment::where('action', 'data_entry_completed')->count())->toBe(0);
})->with([
    'missing' => [null, 'Add how many entries you made on this part.'],
    'fewer than none' => [-3, 'The number of entries can\'t be less than 0.'],
    'a word' => ['twelve', 'Give the number of entries as a whole number, like 12.'],
    'part of one' => ['2.5', 'Give the number of entries as a whole number, like 12.'],
    'too many' => [100001, 'That\'s more entries than one part can have — at most 100,000.'],
]);

it('shows the number on Data Entry\'s Completed tab, Senior Operations\' review and the RFQ\'s page', function () {
    ['rfq' => $rfq, 'dataEntry' => $dataEntry] = startedForEntries();
    sendToFinalizeWith($dataEntry, $rfq, ['entries' => 1234, 'comment' => 'Prices entered']);

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'completed']))->assertOk()
        ->assertSeeInOrder(['<th class="text-end">Entries</th>', 'RFQ7001', '1,234'], false);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('· 1,234 entries', false);

    $rfq->refresh()->finalizePart(1);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('by '.e($dataEntry->name).' · 1,234 entries', false);
});

it('takes the new number when a part sent back to Data Entry is sent to finalize again', function () {
    ['rfq' => $rfq, 'dataEntry' => $dataEntry] = startedForEntries();
    sendToFinalizeWith($dataEntry, $rfq, ['entries' => 12, 'comment' => 'Prices entered']);

    $rfq->refresh()->returnToDataEntry(1, 'Two lines are missing');
    startDataEntryOn($rfq->refresh(), 1, $dataEntry);
    sendToFinalizeWith($dataEntry, $rfq, ['entries' => 14, 'comment' => 'Added the two lines']);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_entries)->toBe(14)
        // Each round's comment keeps its own.
        ->and(RfqComment::where('action', 'data_entry_completed')->oldest('id')->get()->map(fn (RfqComment $comment) => $comment->meta['entries'])->all())->toBe([12, 14]);
});
