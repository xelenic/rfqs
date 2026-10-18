<?php

use App\Models\Rfq;
use App\Models\User;
use App\Notifications\DataEntryPaused;
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
 * RFQ8201 ("Valves"), its one part with Data Entry, and Morgan in Data Entry
 * on it.
 *
 * @return array{rfq: Rfq, morgan: User}
 */
function runningForPauseAlert(): array
{
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8201', 'subject' => 'Valves']), [1 => userWithRole('Sourcing')]);
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq->refresh(), 1, $morgan);

    return ['rfq' => $rfq->refresh(), 'morgan' => $morgan];
}

/**
 * $user presses Pause on part 1 of $rfq, with $payload as the reason and
 * reference.
 *
 * @param  array<string, mixed>  $payload
 */
function pressPause(User $user, Rfq $rfq, array $payload = ['pause_reason' => 'Waiting for the supplier quote', 'pause_reference' => 'PO-4471']): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.pause-data-entry', $rfq), ['part' => 1] + $payload);
}

it('tells Senior Operations and the General Manager when Data Entry pauses, with why', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $operations = userWithRole('Senior Operations');
    $generalManager = userWithRole('General Manager');

    pressPause($morgan, $rfq)->assertSessionHas('status');

    foreach ([$operations, $generalManager] as $person) {
        $alert = $person->notifications()->sole();

        expect($alert->type)->toBe(DataEntryPaused::class)
            ->and($alert->data)->toMatchArray([
                'rfq_id' => $rfq->id,
                'part' => 1,
                'paused_by' => $morgan->id,
                'reason' => 'Waiting for the supplier quote',
                'reference' => 'PO-4471',
                'message' => "{$morgan->name} paused RFQ8201: Waiting for the supplier quote (Ref PO-4471) — Valves",
                'note' => 'Data Entry paused',
            ]);
    }
});

it('leaves out the reference when none was given', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $generalManager = userWithRole('General Manager');

    pressPause($morgan, $rfq, ['pause_reason' => 'Lunch with the client']);

    expect($generalManager->notifications()->sole()->data)
        ->toMatchArray(['reference' => null, 'message' => "{$morgan->name} paused RFQ8201: Lunch with the client — Valves"]);
});

it('shortens a long reason in the alert, keeping all of it in its data', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $generalManager = userWithRole('General Manager');
    $reason = str_repeat('Waiting on the price list. ', 20);

    pressPause($morgan, $rfq, ['pause_reason' => $reason]);

    $data = $generalManager->notifications()->sole()->data;
    expect($data['reason'])->toBe(trim($reason))
        ->and($data['message'])->toContain(Str::limit(trim($reason), 120));
});

it('tells no one else — not other roles, nor a deactivated account', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();

    foreach (['Admin', 'Head of Business Development', 'GM Assistant', 'Business Development'] as $role) {
        userWithRole($role);
    }

    foreach (['Senior Operations', 'General Manager'] as $role) {
        userWithRole($role)->forceFill(['deactivated_at' => now()])->save();
    }

    pressPause($morgan, $rfq);

    expect(User::query()->whereHas('notifications')->count())->toBe(0);
});

it('names the Admin who paused it on Data Entry\'s behalf', function () {
    ['rfq' => $rfq] = runningForPauseAlert();
    $admin = userWithRole('Admin');
    $operations = userWithRole('Senior Operations');

    pressPause($admin, $rfq);

    expect($operations->notifications()->sole()->data)
        ->toMatchArray(['paused_by' => $admin->id])
        ->and($operations->notifications()->sole()->data['message'])->toStartWith("{$admin->name} paused RFQ8201");
});

it('sends nothing when the pause is refused', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $generalManager = userWithRole('General Manager');

    pressPause($morgan, $rfq, ['pause_reason' => ''])->assertSessionHas('error', 'Say why you\'re pausing it.');
    pressPause(userWithRole('Data Entry'), $rfq)->assertForbidden();

    pressPause($morgan, $rfq);
    pressPause($morgan, $rfq)->assertSessionHas('error', 'This part isn\'t running, so there\'s nothing to pause.');

    expect($generalManager->notifications()->count())->toBe(1);
});

it('tells them of every pause, but not of a resume', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $generalManager = userWithRole('General Manager');

    pressPause($morgan, $rfq);
    test()->actingAs($morgan)->patch(route('admin.rfqs.resume-data-entry', $rfq), ['part' => 1])->assertSessionHas('status');

    expect($generalManager->notifications()->count())->toBe(1);

    pressPause($morgan, $rfq, ['pause_reason' => 'Waiting on drawings']);

    expect($generalManager->notifications()->count())->toBe(2)
        ->and($generalManager->notifications()->get()->pluck('data.reason')->sort()->values()->all())->toBe(['Waiting for the supplier quote', 'Waiting on drawings']);
});

it('is in their bell, and opens the RFQ', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = runningForPauseAlert();
    $operations = userWithRole('Senior Operations');

    pressPause($morgan, $rfq);

    test()->actingAs($operations)->get(route('admin.dashboard'))->assertOk()
        ->assertSee("{$morgan->name} paused RFQ8201: Waiting for the supplier quote (Ref PO-4471)")
        ->assertSee('Data Entry paused');

    test()->actingAs($operations)->get(route('admin.notifications.open', $operations->notifications()->sole()->id))
        ->assertRedirect(route('admin.rfqs.show', $rfq));
});
