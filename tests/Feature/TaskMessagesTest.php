<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\User;
use App\RfqDataEraser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
function taskPerson(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * RFQ7001 ("Valves", High), created by Bea in Business Development, kept
 * whole — not yet assigned.
 *
 * @return array{rfq: Rfq, bd: User, ops: User, riley: User, dana: User}
 */
function taskRfq(): array
{
    $bd = taskPerson('Bea Dev', 'Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ7001', 'subject' => 'Valves', 'priority_level' => 'High', 'created_by' => $bd->id]);
    $rfq->planSplit(1);

    return [
        'rfq' => $rfq->refresh(),
        'bd' => $bd,
        'ops' => taskPerson('Olivia Ops', 'Senior Operations'),
        'riley' => taskPerson('Riley Chen', 'Sourcing'),
        'dana' => taskPerson('Dana Entry', 'Data Entry'),
    ];
}

/**
 * The message $to was last sent.
 */
function lastMessageTo(User $to): ?PrivateMessage
{
    return PrivateMessage::query()->where('recipient_id', $to->id)->latest('id')->first();
}

it('tells a Sourcing member in their Messages when a part is assigned to them, from whoever assigned it', function () {
    ['rfq' => $rfq, 'ops' => $ops, 'riley' => $riley] = taskRfq();

    test()->actingAs($ops)->patch(route('admin.rfqs.assign', $rfq), ['assignments' => [1 => $riley->id]])
        ->assertSessionHasNoErrors();

    expect(PrivateMessage::query()->sole())
        ->sender_id->toBe($ops->id)
        ->recipient_id->toBe($riley->id)
        ->rfq_id->toBe($rfq->id)
        ->body->toBe('Assigned you RFQ7001 in Sourcing (High priority) — Valves.');

    // In their Messages and bell, leading back to the RFQ.
    test()->actingAs($riley)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('Olivia Ops')
        ->assertSee('Assigned you RFQ7001 in Sourcing (High priority)');
    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Olivia Ops: Assigned you RFQ7001 in Sourcing');
    test()->actingAs($riley)->get(route('admin.messages.show', $ops))->assertOk()
        ->assertSee('href="'.route('admin.rfqs.show', $rfq).'"', false)
        ->assertSee('Open RFQ7001');
});

it('tells whom in Data Entry a part is assigned to, with Sourcing\'s note — or everyone there when it\'s anyone\'s', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'dana' => $dana] = taskRfq();
    $pat = taskPerson('Pat Entry', 'Data Entry');
    $rfq->assignSourcingParts([1 => $riley->id]);

    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), [
        'part' => 1, 'comment' => 'Prices are in the sheet', 'line_items' => 24, 'data_entry_user_id' => $dana->id,
    ])->assertSessionHasNoErrors();

    expect(PrivateMessage::query()->sole())
        ->sender_id->toBe($riley->id)
        ->recipient_id->toBe($dana->id)
        ->rfq_comment_id->toBe($rfq->comments()->where('action', 'sourcing_completed')->sole()->id)
        ->body->toBe("Assigned you RFQ7001 for Data Entry (24 line items to add) — Valves:\nPrices are in the sheet");

    // Another, left with anyone in Data Entry.
    $other = Rfq::factory()->create(['rfq_number' => 'RFQ7002', 'subject' => 'Pumps']);
    $other->planSplit(1);
    $other->refresh()->assignSourcingParts([1 => $riley->id]);
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $other), ['part' => 1, 'comment' => 'Over to you', 'line_items' => 5])
        ->assertSessionHasNoErrors();

    expect(PrivateMessage::query()->where('rfq_id', $other->id)->orderBy('recipient_id')->pluck('recipient_id')->all())->toBe([$dana->id, $pat->id])
        ->and(lastMessageTo($pat)->body)->toBe("Assigned RFQ7002 to Data Entry — anyone there can start it (5 line items to add) — Pumps:\nOver to you");
});

it('tells the Sourcing member when Data Entry sends their part to finalize, or back to them', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'dana' => $dana] = taskRfq();
    $rfq->assignSourcingParts([1 => $riley->id]);
    $rfq->refresh()->completeSourcingPart(1, 'Prices are in', $dana, 24);
    startDataEntryOn($rfq, 1, $dana);

    test()->actingAs($dana)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'entries' => 24, 'comment' => 'All entered'])
        ->assertSessionHasNoErrors();

    expect(lastMessageTo($riley))
        ->sender_id->toBe($dana->id)
        ->body->toBe("Sent RFQ7001 to you to finalize (24 line items added) — Valves:\nAll entered");

    // Riley sends it back to Data Entry: Dana, who did it, hears why.
    test()->actingAs($riley)->patch(route('admin.rfqs.return-data-entry', $rfq), ['part' => 1, 'reason' => 'Two prices are wrong'])
        ->assertSessionHasNoErrors();

    expect(lastMessageTo($dana))
        ->sender_id->toBe($riley->id)
        ->body->toBe("Returned RFQ7001 to you in Data Entry — Valves:\nTwo prices are wrong");

    // And Data Entry returns it to Sourcing.
    test()->actingAs($dana)->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 1, 'reason' => 'The supplier list is missing'])
        ->assertSessionHasNoErrors();

    expect(lastMessageTo($riley))
        ->sender_id->toBe($dana->id)
        ->body->toBe("Returned RFQ7001 to you in Sourcing — Valves:\nThe supplier list is missing");
});

it('tells whoever a reviewer sends it back to, once each, with the reason', function (string $target, string $who) {
    ['rfq' => $rfq, 'ops' => $ops] = $cast = taskRfq();
    $rfq->update(['operations_assigned_by' => $ops->id, 'operations_assigned_at' => now()]);
    $rfq->assignSourcingParts([1 => $cast['riley']->id]);
    $rfq->refresh()->completeSourcingPart(1, 'Prices are in', $cast['dana'], 24);
    $rfq->refresh()->completeDataEntryPart(1, $cast['dana'], 'All entered', 24);
    $rfq->refresh()->finalizePart(1);
    PrivateMessage::query()->delete();

    test()->actingAs($ops)->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => $target, 'reason' => 'Please check the quantities'])
        ->assertSessionHasNoErrors();

    $message = PrivateMessage::query()->sole();
    expect($message->recipient_id)->toBe($cast[$who]->id)
        ->and($message->sender_id)->toBe($ops->id)
        ->and($message->rfq_id)->toBe($rfq->id)
        ->and($message->body)->toEndWith("— Valves:\nPlease check the quantities");
})->with([
    'Business Development, its creator' => ['business_development', 'bd'],
    'Sourcing, the part\'s member — once' => ['sourcing', 'riley'],
    'Data Entry, whoever did it' => ['data_entry', 'dana'],
]);

it('tells both Sourcing members when a part changes hands', function () {
    ['rfq' => $rfq, 'ops' => $ops, 'riley' => $riley] = taskRfq();
    $sam = taskPerson('Sam Rivera', 'Sourcing');
    $rfq->assignSourcingParts([1 => $riley->id]);

    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id, 'reason' => 'Riley is on leave'])
        ->assertSessionHasNoErrors();

    expect(lastMessageTo($sam)->body)->toBe("Gave you RFQ7001 in Sourcing — it was Riley Chen's — Valves:\nRiley is on leave")
        ->and(lastMessageTo($riley)->body)->toBe("Gave RFQ7001 to Sam Rivera — it's no longer yours — Valves:\nRiley is on leave");
});

it('never messages whoever did it, nor a deactivated account', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'dana' => $dana] = taskRfq();
    $gone = taskPerson('Gone Entry', 'Data Entry');
    $gone->forceFill(['deactivated_at' => now()])->save();

    // Assigned by Riley themselves: nobody to tell.
    $rfq->assignSourcingParts([1 => $riley->id], $riley);
    expect(PrivateMessage::query()->count())->toBe(0);

    $rfq->refresh()->completeSourcingPart(1, 'Over to anyone', null, 3);

    expect(PrivateMessage::query()->pluck('recipient_id')->all())->toBe([$dana->id]);
});

it('clears task messages with the RFQ data, keeping messages people wrote', function () {
    ['rfq' => $rfq, 'ops' => $ops, 'riley' => $riley] = taskRfq();
    $rfq->assignSourcingParts([1 => $riley->id], $ops);
    $written = $ops->sentMessages()->create(['recipient_id' => $riley->id, 'body' => 'Lunch?']);

    expect(PrivateMessage::query()->count())->toBe(2)
        ->and(RfqDataEraser::tables())->not->toContain('private_messages');

    RfqDataEraser::clear();

    expect(PrivateMessage::query()->pluck('id')->all())->toBe([$written->id]);
});
