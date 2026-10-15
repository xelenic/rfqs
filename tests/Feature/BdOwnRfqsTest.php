<?php

use App\Models\Rfq;
use App\Models\RfqCommentAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Two Business Development members, an RFQ of each at every stage they look
 * at — pending, sent back to them, ready to close, closed — and one pending
 * whose creator is no longer on record.
 *
 * @return array{bailey: User, taylor: User}
 */
function twoBdMembers(): array
{
    $bailey = userWithRole('Business Development');
    $taylor = userWithRole('Business Development');

    foreach (['Bailey' => $bailey, 'Taylor' => $taylor] as $name => $member) {
        Rfq::factory()->create(['subject' => "{$name} pending", 'wc_number' => 'WC'.($name === 'Bailey' ? '1111' : '2222'), 'created_by' => $member->id]);
        Rfq::factory()->create(['subject' => "{$name} sent back", 'reject_target_stage' => 'business_development', 'created_by' => $member->id]);
        Rfq::factory()->create(['subject' => "{$name} to close", 'stage' => 'bd_closing', 'created_by' => $member->id]);
        Rfq::factory()->create(['subject' => "{$name} closed", 'status' => 'Completed', 'stage' => 'closed', 'bd_closed_at' => now(), 'created_by' => $member->id]);
    }

    Rfq::factory()->create(['subject' => 'Nobody\'s pending', 'created_by' => null]);

    return ['bailey' => $bailey, 'taylor' => $taylor];
}

it('shows Business Development only the RFQs they created, on every list — Closed too', function (array $query, string $mine, string $theirs) {
    ['bailey' => $bailey] = twoBdMembers();

    test()->actingAs($bailey)->get(route('admin.rfqs.index', $query))->assertOk()
        ->assertSee($mine)
        ->assertDontSee($theirs);
})->with([
    'Pending' => [['status' => 'Pending'], 'Bailey pending', 'Taylor pending'],
    'Returns' => [['status' => 'Pending', 'view' => 'returns'], 'Bailey sent back', 'Taylor sent back'],
    'Ready to Close' => [['status' => 'Pending', 'view' => 'closing'], 'Bailey to close', 'Taylor to close'],
    'Closed' => [['status' => 'Completed'], 'Bailey closed', 'Taylor closed'],
]);

it('keeps an RFQ whose creator is no longer on record with all of Business Development', function () {
    ['bailey' => $bailey, 'taylor' => $taylor] = twoBdMembers();

    foreach ([$bailey, $taylor] as $member) {
        test()->actingAs($member)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertSee('Nobody&#039;s pending', false);
    }

    // Deleting a member leaves theirs with the rest.
    $taylorsRfq = Rfq::query()->where('subject', 'Taylor pending')->sole();
    $taylor->delete();

    expect($taylorsRfq->refresh()->isVisibleTo($bailey))->toBeTrue();
});

it('keeps someone else\'s RFQ — its page, photos and actions — from Business Development, saying why', function () {
    ['bailey' => $bailey, 'taylor' => $taylor] = twoBdMembers();
    $theirs = Rfq::query()->where('subject', 'Taylor pending')->sole();
    $mine = Rfq::query()->where('subject', 'Bailey pending')->sole();
    $photo = RfqCommentAttachment::factory()->create(['rfq_id' => $theirs->id]);

    test()->actingAs($bailey)->get(route('admin.rfqs.show', $theirs))
        ->assertForbidden()
        ->assertSee('This RFQ isn&#039;t one of yours — you can only open the RFQs you created.', false);
    test()->actingAs($bailey)->get(route('admin.rfqs.attachments.show', $photo))->assertForbidden();
    test()->actingAs($bailey)->patch(route('admin.rfqs.update', $theirs), ['subject' => 'Changed'])->assertForbidden();

    expect($theirs->refresh()->subject)->toBe('Taylor pending')
        ->and($theirs->canBeEditedBy($bailey))->toBeFalse()
        ->and($mine->canBeEditedBy($bailey))->toBeTrue();

    test()->actingAs($bailey)->get(route('admin.rfqs.show', $mine))->assertOk();
});

it('counts only their own on the sidebar badges and the dashboard', function () {
    ['bailey' => $bailey] = twoBdMembers();

    test()->actingAs($bailey)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('title="1 sent back by a reviewer"', false)
        ->assertSee('title="1 ready to close"', false)
        ->assertViewHas('overview', function (array $overview) {
            // Bailey's three open ones and nobody's.
            expect($overview)->toMatchArray(['pending' => 4, 'readyToClose' => 1, 'closed' => 1])
                ->and($overview['readyToCloseRfqs']->pluck('subject')->all())->toBe(['Bailey to close']);

            return true;
        })
        ->assertViewHas('recentRfqs', fn ($rfqs) => $rfqs->pluck('subject')->doesntContain(fn (string $subject) => str_starts_with($subject, 'Taylor')));
});

it('suggests only their own RFQs in the WC number search', function () {
    ['bailey' => $bailey] = twoBdMembers();

    test()->actingAs($bailey)->getJson(route('admin.rfqs.suggest', ['q' => 'WC']))->assertOk()
        ->assertJsonPath('results.*.subject', fn (array $subjects) => in_array('Bailey pending', $subjects, true) && ! in_array('Taylor pending', $subjects, true));
});

it('leaves everyone else seeing every RFQ — Admin, the other roles, and Business Development with another role too', function (Closure $viewer) {
    twoBdMembers();

    test()->actingAs($viewer())->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()
        ->assertSee('Bailey closed')
        ->assertSee('Taylor closed');
})->with([
    'Admin' => [fn () => userWithRole('Admin')],
    'Senior Operations' => [fn () => userWithRole('Senior Operations')],
    'Business Development and Senior Operations' => [fn () => userWithRole('Business Development')->assignRole('Senior Operations')],
]);
