<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
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
function inRole(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * The business's example: Sourcing may message Data Entry, Admin and Senior
 * Operations only; Data Entry, Sourcing, Admin and Senior Operations only.
 */
function exampleMessagingRules(): void
{
    Setting::putMessageRoles([
        'Sourcing' => ['Admin', 'Data Entry', 'Senior Operations'],
        'Data Entry' => ['Admin', 'Senior Operations', 'Sourcing'],
    ]);
}

it('lets an Admin set, role by role, who can message whom on Settings → Messaging', function () {
    $admin = userWithRole('Admin');
    $id = fn (string $role) => Role::findByName($role)->id;

    $html = test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'messaging']))->assertOk()
        ->assertSee('action="'.route('admin.settings.message-roles').'"', false)
        ->assertSee('Admin can always message anyone.')
        ->getContent();
    expect($html)->toContain('data-message-role="Sourcing"')->not->toContain('data-message-role="Admin"');

    test()->actingAs($admin)->put(route('admin.settings.message-roles'), ['limits' => [
        $id('Sourcing') => ['limited' => '1', 'roles' => [$id('Data Entry'), $id('Admin'), $id('Senior Operations')]],
        $id('Data Entry') => ['limited' => '1', 'roles' => [$id('Sourcing'), $id('Admin'), $id('Senior Operations')]],
        $id('General Manager') => ['limited' => '0', 'roles' => [$id('Sourcing')]],
        $id('Admin') => ['limited' => '1', 'roles' => [$id('Sourcing')]],
    ]])->assertRedirect()->assertSessionHas('status', 'Messaging saved.');

    expect(Setting::messageRoles())->toBe([
        'Data Entry' => ['Admin', 'Senior Operations', 'Sourcing'],
        'Sourcing' => ['Admin', 'Data Entry', 'Senior Operations'],
    ]);

    $html = test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'messaging']))->getContent();
    $sourcing = Str::betweenFirst($html, 'data-message-role="Sourcing"', '</tr>');
    expect($sourcing)->toContain('id="message-limit-'.$id('Sourcing').'-only" checked')
        ->toContain('id="message-limit-'.$id('Sourcing').'-to-'.$id('Data Entry').'" checked')
        ->not->toContain('id="message-limit-'.$id('Sourcing').'-to-'.$id('General Manager').'" checked');

    test()->actingAs(userWithRole('Senior Operations'))->put(route('admin.settings.message-roles'), ['limits' => []])->assertForbidden();
});

it('keeps a limited role to the roles it may message — on send, in New message, and on a conversation', function () {
    exampleMessagingRules();
    $riley = inRole('Riley Chen', 'Sourcing');
    $dana = inRole('Dana Entry', 'Data Entry');
    $morgan = inRole('Morgan Ellis', 'General Manager');
    $ops = inRole('Olivia Park', 'Senior Operations');

    expect($riley->canMessage($dana))->toBeTrue()
        ->and($riley->canMessage($ops))->toBeTrue()
        ->and($riley->canMessage($morgan))->toBeFalse()
        ->and($riley->messagingLimitText())->toBe('As Sourcing, you can message Admin, Data Entry and Senior Operations — and answer anyone who writes to you.');

    test()->actingAs($riley)->from(route('admin.messages.index'))
        ->post(route('admin.messages.store'), ['new_conversation' => '1', 'recipient_id' => $morgan->id, 'body' => 'Hi'])
        ->assertRedirect(route('admin.messages.index'))
        ->assertSessionHasErrors(['recipient_id' => 'You can\'t message Morgan Ellis. As Sourcing, you can message Admin, Data Entry and Senior Operations — and answer anyone who writes to you.']);
    test()->actingAs($riley)->postJson(route('admin.messages.store'), ['recipient_id' => $morgan->id, 'body' => 'Hi'])->assertStatus(422);
    test()->actingAs($riley)->post(route('admin.messages.store'), ['recipient_id' => $dana->id, 'body' => 'Hi'])->assertRedirect(route('admin.messages.show', $dana));
    expect(PrivateMessage::query()->pluck('recipient_id')->all())->toBe([$dana->id]);

    $html = test()->actingAs($riley)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('As Sourcing, you can message Admin, Data Entry and Senior Operations')
        ->getContent();
    expect(Str::betweenFirst($html, 'class="new-message-people', '</div>'))
        ->toContain('data-person="'.$dana->id.'"')->toContain('data-person="'.$ops->id.'"')
        ->not->toContain('data-person="'.$morgan->id.'"');

    test()->actingAs($riley)->get(route('admin.messages.show', $morgan))->assertOk()
        ->assertSee('You can\'t message Morgan Ellis.', false)
        ->assertDontSee('placeholder="Write a private message to Morgan Ellis', false);
});

it('lets them answer someone who wrote to them, whatever their role', function () {
    exampleMessagingRules();
    $riley = inRole('Riley Chen', 'Sourcing');
    $morgan = inRole('Morgan Ellis', 'General Manager');

    test()->actingAs($morgan)->post(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => 'Where are the pump quotes?'])->assertSessionHasNoErrors();

    test()->actingAs($riley->fresh())->post(route('admin.messages.store'), ['recipient_id' => $morgan->id, 'body' => 'Sending them now.'])
        ->assertRedirect(route('admin.messages.show', $morgan))
        ->assertSessionHasNoErrors();
    test()->actingAs($riley->fresh())->get(route('admin.messages.show', $morgan))->assertOk()
        ->assertSee('placeholder="Write a private message to Morgan Ellis', false);
});

it('leaves anyone not limited — and an Admin always — free to message anyone, someone with two roles both roles\' lists', function () {
    exampleMessagingRules();
    $morgan = inRole('Morgan Ellis', 'General Manager');
    $riley = inRole('Riley Chen', 'Sourcing');
    $ben = inRole('Ben Dev', 'Business Development');
    $both = tap(inRole('Sam Both', 'Sourcing'))->assignRole('Data Entry');
    Setting::putMessageRoles(Setting::messageRoles() + ['Admin' => []]);

    expect($morgan->canMessage($riley))->toBeTrue()
        ->and(userWithRole('Admin')->canMessage($ben))->toBeTrue()
        ->and($both->messageableRoles())->toBe(['Admin', 'Data Entry', 'Senior Operations', 'Sourcing'])
        ->and($both->canMessage($riley))->toBeTrue()
        ->and($both->canMessage($ben))->toBeFalse();

    Setting::putMessageRoles([]);
    expect($riley->fresh()->canMessage($ben))->toBeTrue()
        ->and($riley->fresh()->messagingLimitText())->toBeNull();
});

it('doesn\'t hold back what the system sends — tasks, @mentions and RFQ comments', function () {
    exampleMessagingRules();
    $riley = inRole('Riley Chen', 'Sourcing');
    $ben = inRole('Ben Dev', 'Business Development');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8901', 'created_by' => $ben->id]), [1 => $riley]);

    test()->actingAs($riley)->post(route('admin.rfqs.comments.store', $rfq), ['body' => 'Quotes are in.'])->assertSessionHasNoErrors();

    expect(PrivateMessage::query()->where('recipient_id', $ben->id)->value('body'))->toStartWith('Commented on RFQ8901');
});

it('hides Private message on the hover card of someone their role can\'t message', function () {
    exampleMessagingRules();
    $riley = inRole('Riley Chen', 'Sourcing');
    $morgan = inRole('Morgan Ellis', 'General Manager');
    $dana = inRole('Dana Entry', 'Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8902']), [1 => $riley]);
    $rfq->comments()->create(['user_id' => $morgan->id, 'body' => 'Any news?']);
    $rfq->comments()->create(['user_id' => $dana->id, 'body' => 'On it.']);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('id="userCardBlocked"', false)
        ->getContent();

    expect(Str::betweenFirst($html, 'data-user-id="'.$morgan->id.'"', '>'))->toContain('data-can-message="0"')
        ->and(Str::betweenFirst($html, 'data-user-id="'.$dana->id.'"', '>'))->toContain('data-can-message="1"');
});
