<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

function gmAssistantReturnsUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns'] + $query);
}

/**
 * RFQ5001, split between the given parts' Sourcing members, every part all
 * the way through to the General Manager's review.
 *
 * @return array{assistant: User, gm: User, rfq: Rfq}
 */
function throughToGm(int $parts = 1): array
{
    $assistant = userWithRole('GM Assistant');
    $gm = userWithRole('General Manager');
    $sourcing = userWithRole('Sourcing');

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ5001', 'subject' => 'Lobby lighting']), array_fill_keys(range(1, $parts), $sourcing));
    foreach (range(1, $parts) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
        $rfq->refresh()->approveHeadOfBdPart($part, userWithRole('Head of Business Development'));
        $rfq->refresh()->recordGmAssistantPart($part, $assistant);
    }
    expect($rfq->refresh()->stage)->toBe('gm_review');

    return ['assistant' => $assistant, 'gm' => $gm, 'rfq' => $rfq->refresh()];
}

it('shows the empty state until the General Manager sends something back', function () {
    ['assistant' => $assistant] = throughToGm();

    test()->actingAs($assistant)->get(gmAssistantReturnsUrl())->assertOk()
        ->assertSee('<title>Returns', false)
        ->assertDontSee('>RFQ5001', false)
        ->assertSee('Nothing\'s been sent back to GM Assistant.', false);
});

it('lists what the General Manager sent back to GM Assistant, with who and why, and Submit right there — and nowhere else', function () {
    ['assistant' => $assistant, 'gm' => $gm, 'rfq' => $rfq] = throughToGm();

    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'gm_assistant', 'reason' => 'Payment terms should be 45 days'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBe('gm_assistant')
        ->and(Rfq::gmAssistantReturnsCount())->toBe(1)
        ->and(Rfq::gmAssistantReviewCount())->toBe(0)
        ->and(Rfq::gmReviewCount())->toBe(0);

    test()->actingAs($assistant)->get(gmAssistantReturnsUrl())->assertOk()
        ->assertSee('>RFQ5001', false)
        ->assertSee('Sent back by General Manager ('.$gm->name.'): Payment terms should be 45 days')
        ->assertSee('Returned At')
        ->assertSee('data-action="'.route('admin.rfqs.gm-assistant-details', $rfq).'"', false)
        ->assertSee('data-label="RFQ5001"', false)
        ->assertSee('id="gmAssistantSubmitModal"', false)
        ->assertSee('title="1 sent back by the General Manager"', false);

    // Not on their own Review page.
    test()->actingAs($assistant)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<title>Review', false)
        ->assertDontSee('>RFQ5001', false);
});

it('goes on to the General Manager once it\'s submitted again from Returns, and off the list', function () {
    ['assistant' => $assistant, 'gm' => $gm, 'rfq' => $rfq] = throughToGm();
    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'gm_assistant', 'reason' => 'Payment terms should be 45 days']);

    test()->actingAs($assistant)
        ->from(gmAssistantReturnsUrl())
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors()
        ->assertRedirect(gmAssistantReturnsUrl());

    expect($rfq->refresh())
        ->stage->toBe('gm_review')
        ->reject_target_stage->toBeNull()
        ->and(Rfq::gmAssistantReturnsCount())->toBe(0)
        ->and(Rfq::gmReviewCount())->toBe(1);

    test()->actingAs($gm)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('>RFQ5001', false);
});

it('holds a split\'s other parts off the General Manager\'s page until GM Assistant has dealt with the one sent back', function () {
    ['assistant' => $assistant, 'gm' => $gm, 'rfq' => $rfq] = throughToGm(parts: 2);

    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'gm_assistant', 'reason' => 'P1 terms are wrong'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBe('gm_assistant')
        ->and(Rfq::gmReviewCount())->toBe(0);

    test()->actingAs($gm)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('>RFQ5001', false);
    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 2])->assertStatus(422);

    test()->actingAs($assistant)->get(gmAssistantReturnsUrl())->assertOk()
        ->assertSee('RFQ5001-P1 of P2')
        ->assertDontSee('RFQ5001-P2 of P2');

    test()->actingAs($assistant)
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors();

    expect(Rfq::gmAssistantReturnsCount())->toBe(0)
        ->and(Rfq::gmReviewCount())->toBe(2);
});

it('leaves out what the General Manager sends back to anyone else', function () {
    ['assistant' => $assistant, 'gm' => $gm, 'rfq' => $rfq] = throughToGm();

    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'For the Head'])
        ->assertSessionHasNoErrors();

    expect(Rfq::gmAssistantReturnsCount())->toBe(0)
        ->and(Rfq::headOfBdReturnsCount())->toBe(1);
    test()->actingAs($assistant)->get(gmAssistantReturnsUrl())->assertOk()->assertDontSee('>RFQ5001', false);
});

it('shows Admin GM Assistant\'s Returns page, counted on the grouped sidebar', function () {
    ['gm' => $gm, 'rfq' => $rfq] = throughToGm();
    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'gm_assistant', 'reason' => 'Payment terms should be 45 days']);

    expect(Rfq::queueCounts()['gm_assistant_returns'])->toBe(1);

    test()->actingAs(userWithRole('Admin'))->get(gmAssistantReturnsUrl(['role' => 'gm-assistant']))->assertOk()
        ->assertSee('Returns · GM Assistant')
        ->assertSee('>RFQ5001', false)
        ->assertSee('data-action="'.route('admin.rfqs.gm-assistant-details', $rfq).'"', false)
        ->assertSee('title="1 sent back by the General Manager"', false);
});
