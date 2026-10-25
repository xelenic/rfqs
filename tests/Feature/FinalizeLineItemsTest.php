<?php

use App\Models\Rfq;
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
 * RFQ8601 ("Hoses"), Riley's one part sent to finalize by Data Entry: 24
 * line items to add, 22 added.
 *
 * @return array{rfq: Rfq, riley: User}
 */
function awaitingRileysFinalize(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8601', 'subject' => 'Hoses']), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1, null, null, 24);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'), 'Entered', 22);

    return ['rfq' => $rfq->refresh(), 'riley' => $riley];
}

/**
 * $user finalizes part 1 of $rfq with $payload.
 *
 * @param  array<string, mixed>  $payload
 */
function finalizeWith(User $user, Rfq $rfq, array $payload): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1, 'redirect_status' => 'Pending'] + $payload);
}

it('opens a check of the line items from Finalize — what was asked, added, the difference and the history — asking for the submitted line items', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('id="finalizeModal" data-field="submitted_line_items"', false)
        ->assertSee('Finalize — submit line items')
        ->assertSeeInOrder(['Line Items to Add', 'Line Items Added', 'Difference', 'Submitted Line Items'])
        ->assertSee('Line items history')
        ->getContent();

    $button = Str::betweenFirst($html, 'js-finalize"', '</button>');
    expect($button)
        ->toContain('data-bs-target="#finalizeModal"')
        ->toContain('data-action="'.route('admin.rfqs.finalize', $rfq).'"')
        ->toContain('data-part="1"')
        ->toContain('data-redirect-status="Pending"')
        ->and(popupParts($button))->toBe([['part' => 1, 'label' => 'RFQ8601', 'who' => 'Riley Chen', 'to_add' => 24, 'added' => 22, 'difference' => -2, 'submitted' => null]]);

    preg_match('/data-parts="([^"]*)"/', $button, $parts);
    expect(collect(json_decode(html_entity_decode($parts[1], ENT_QUOTES), true)[0]['history'])->pluck('title'))
        ->toContain('Line items added — sent to finalize');

    // Only Submitted Line Items is theirs to fill in; the rest are read only, and not sent.
    $part = Str::between($html, '<template id="finalizePart">', '</template>');
    expect(substr_count($part, ' readonly tabindex="-1"'))->toBe(3)
        ->and(substr_count($part, 'name='))->toBe(0)
        ->and($part)->toContain('type="number" class="form-control js-actual"');
});

it('is on the RFQ page and Sourcing\'s dashboard too, coming back to each', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();

    foreach ([route('admin.rfqs.show', $rfq) => 'show', route('admin.dashboard') => 'dashboard'] as $url => $returnTo) {
        $html = test()->actingAs($riley)->get($url)->assertOk()->assertSee('id="finalizeModal"', false)->getContent();

        expect(Str::betweenFirst($html, 'js-finalize"', '</button>'))->toContain('data-return-to="'.$returnTo.'"');
    }
});

it('keeps the submitted line items with the part, sending it on to Senior Operations\' review', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();

    finalizeWith($riley, $rfq, ['submitted_line_items' => [1 => 23]])
        ->assertSessionHas('status', 'Finalized RFQ8601 — sent to Senior Operations\' review.');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->submitted_line_items)->toBe(23)
        ->and($part->submittedLineItems())->toBe(23)
        ->and($rfq->stage)->toBe('senior_ops_review');

    expect(collect($rfq->lineItemsHistory(1))->firstWhere('kind', 'submitted'))->toMatchArray([
        'role' => 'Sourcing',
        'who' => 'Riley Chen',
        'title' => 'Submitted line items — finalized',
        'count' => 23,
        'change' => '1 more than Data Entry added',
        'tone' => 'off',
    ]);

    test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('23 line items submitted');
});

it('refuses a Finalize without the submitted line items, finalizing nothing', function (array $payload, string $message) {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();

    finalizeWith($riley, $rfq, $payload)->assertSessionHas('error', $message);

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeTrue();
})->with([
    'none' => [[], 'Enter the line items you\'re submitting on RFQ8601.'],
    'a word' => [['submitted_line_items' => [1 => 'lots']], 'Give the submitted line items as a whole number, like 12.'],
    'fewer than none' => [['submitted_line_items' => [1 => -3]], 'The submitted line items can\'t be less than 0.'],
]);

it('shows Senior Operations and the Head of Business Development what Sourcing submitted, and measures the actual count against it', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();
    finalizeWith($riley, $rfq, ['submitted_line_items' => [1 => 23]]);
    $ops = userWithRole('Senior Operations');

    $html = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()->getContent();
    expect(popupParts(Str::betweenFirst($html, 'js-senior-ops-approve"', '</button>')))
        ->toBe([['part' => 1, 'label' => 'RFQ8601', 'who' => 'Riley Chen', 'to_add' => 24, 'added' => 22, 'difference' => -2, 'submitted' => 23]]);

    $rfq->refresh()->approveSeniorOpsPart(1, $ops, 21);

    expect(collect($rfq->refresh()->lineItemsHistory(1))->firstWhere('kind', 'verified'))
        ->toMatchArray(['count' => 21, 'change' => '2 fewer than Sourcing submitted', 'tone' => 'off']);

    $html = test()->actingAs(userWithRole('Head of Business Development'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    expect(popupParts(Str::betweenFirst($html, 'js-head-of-bd-approve"', '</button>'))[0])->toMatchArray(['submitted' => 23, 'verified' => 21]);
});

it('asks again once the part comes back past Finalize — the earlier count no longer counts', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();
    finalizeWith($riley, $rfq, ['submitted_line_items' => [1 => 23]]);

    $rfq->refresh()->rejectPartToStage(1, 'data_entry', 'Recheck the sizes', userWithRole('Senior Operations'), 'senior_ops_review');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->submittedLineItems())->toBeNull();

    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'), 'Rechecked', 24);
    finalizeWith($riley, $rfq->refresh(), ['submitted_line_items' => [1 => 24]])->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->submittedLineItems())->toBe(24);
});

it('goes Back to the RFQ\'s quick view when opened from it', function () {
    ['rfq' => $rfq, 'riley' => $riley] = awaitingRileysFinalize();

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    expect(Str::of($html)->matchAll('/class="btn btn-sm btn-success js-finalize".*?data-back-modal="([^"]*)"/s')->filter()->all())->not->toBeEmpty();
});
