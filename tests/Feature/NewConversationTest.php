<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
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
function messenger(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

it('offers New message on Messages — everyone active to pick from, with their roles, but not themselves', function () {
    $olivia = messenger('Olivia Park', 'Senior Operations');
    $riley = messenger('Riley Chen', 'Sourcing');
    messenger('Pat Gone', 'Data Entry')->forceFill(['deactivated_at' => now()])->save();
    $hr = tap(User::factory()->create(['name' => 'Harper Hr']))->assignRole(Role::findOrCreate('HR Executive'));

    $html = test()->actingAs($olivia)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('data-bs-target="#newMessageModal"', false)
        ->assertSee('id="newMessageModal"', false)
        ->getContent();
    $people = Str::betweenFirst($html, 'class="new-message-people', '</div>');

    expect($people)->toContain('data-person="'.$riley->id.'"')
        ->toContain('data-person="'.$hr->id.'"')
        ->toContain('data-search="riley chen sourcing"')
        ->not->toContain('data-person="'.$olivia->id.'"')
        ->not->toContain('Pat Gone')
        ->and(Str::betweenFirst($html, 'id="newMessageModal"', '</form>'))
        ->toContain('action="'.route('admin.messages.store').'"')
        ->toContain('name="new_conversation" value="1"');
});

it('starts a conversation with whoever they pick, and opens it', function () {
    $olivia = messenger('Olivia Park', 'Senior Operations');
    $riley = messenger('Riley Chen', 'Sourcing');

    test()->actingAs($olivia)->from(route('admin.messages.index'))
        ->post(route('admin.messages.store'), ['new_conversation' => '1', 'recipient_id' => $riley->id, 'body' => '  Can you call the supplier today?  '])
        ->assertRedirect(route('admin.messages.show', $riley))
        ->assertSessionHas('status', 'Message sent to Riley Chen.');

    expect(PrivateMessage::query()->sole())
        ->sender_id->toBe($olivia->id)
        ->recipient_id->toBe($riley->id)
        ->body->toBe('Can you call the supplier today?');

    test()->actingAs($olivia)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('Riley Chen')
        ->assertSee('Can you call the supplier today?');
    test()->actingAs($riley)->get(route('admin.messages.index'))->assertOk()
        ->assertSee('Olivia Park')
        ->assertSee('title="1 unread"', false);
});

it('carries on a conversation there already', function () {
    $olivia = messenger('Olivia Park', 'Senior Operations');
    $riley = messenger('Riley Chen', 'Sourcing');
    $riley->sentMessages()->create(['recipient_id' => $olivia->id, 'body' => 'Morning!']);

    test()->actingAs($olivia)->post(route('admin.messages.store'), ['new_conversation' => '1', 'recipient_id' => $riley->id, 'body' => 'Morning — quick one.'])
        ->assertRedirect(route('admin.messages.show', $riley));

    test()->actingAs($olivia)->get(route('admin.messages.show', $riley))->assertOk()
        ->assertSeeInOrder(['Morning!', 'Morning — quick one.']);
});

it('reopens New message saying what\'s wrong, with what they wrote', function (Closure $payload, string $message) {
    $olivia = messenger('Olivia Park', 'Senior Operations');
    $riley = messenger('Riley Chen', 'Sourcing');

    test()->actingAs($olivia)->from(route('admin.messages.index'))
        ->post(route('admin.messages.store'), ['new_conversation' => '1'] + $payload($riley))
        ->assertRedirect(route('admin.messages.index'));

    test()->actingAs($olivia)->get(route('admin.messages.index'))->assertOk()
        ->assertSee($message)
        ->assertSee('getElementById(\'newMessageModal\')).show()', false);

    expect(PrivateMessage::query()->count())->toBe(0);
})->with([
    'nobody picked' => [fn (User $riley) => ['body' => 'Hello there'], 'Choose who to send it to.'],
    'nothing written' => [fn (User $riley) => ['recipient_id' => $riley->id, 'body' => ''], 'Write a message to send.'],
    'a deactivated account' => [function (User $riley) {
        $riley->forceFill(['deactivated_at' => now()])->save();

        return ['recipient_id' => $riley->id, 'body' => 'Hello there'];
    }, 'Riley Chen\'s account has been deactivated — they won\'t see it.'],
]);
