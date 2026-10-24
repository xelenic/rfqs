<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Someone called $name holding $roles.
 */
function staffNamed(string $name, string ...$roles): User
{
    $person = tap(userWithRole($roles[0]))->update(['name' => $name]);

    foreach (array_slice($roles, 1) as $role) {
        $person->assignRole($role);
    }

    return $person;
}

/**
 * The @mention dropdown's people as the RFQ page hands them to admin.js.
 *
 * @return array<int, array<string, mixed>>
 */
function mentionListOn(Rfq $rfq, User $viewer): array
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    return json_decode(Str::betweenFirst($html, '<script type="application/json" id="rfq-mention-users">', '</script>'), true);
}

it('offers all the active staff to @mention — each with their roles and picture or initial', function () {
    staffNamed('Riley Chen', 'Sourcing');
    $morgan = staffNamed('Morgan Ito', 'Data Entry', 'GM Assistant');
    staffNamed('Quinn Alder', 'General Manager');
    staffNamed('Alex Fernando', 'HR Executive');
    staffNamed('Pat Gone', 'Sourcing')->forceFill(['deactivated_at' => now()])->save();
    $morgan->forceFill(['avatar_path' => 'avatars/morgan.jpg'])->save();
    $viewer = staffNamed('Olivia Park', 'Senior Operations');

    $list = collect(mentionListOn(Rfq::factory()->create(), $viewer))->keyBy('name');

    expect($list->keys()->all())->toBe(['Alex Fernando', 'Morgan Ito', 'Olivia Park', 'Quinn Alder', 'Riley Chen'])
        ->and($list['Morgan Ito'])->toMatchArray(['id' => $morgan->id, 'role' => 'Data Entry, GM Assistant', 'avatar' => $morgan->avatarUrl(), 'initial' => 'M'])
        ->and($list['Quinn Alder'])->toMatchArray(['role' => 'General Manager', 'avatar' => null, 'initial' => 'Q'])
        ->and($list['Alex Fernando']['role'])->toBe('HR Executive');
});

it('highlights a mention of anyone on the staff — whole names only, the longest first', function () {
    staffNamed('Sam', 'Data Entry');
    staffNamed('Sam Rivera', 'Sourcing');
    staffNamed('Quinn Alder', 'General Manager');
    $comment = new RfqComment(['body' => 'Over to @Sam Rivera and @Quinn Alder — not @Samuel.']);

    expect($comment->bodyWithMentions(RfqComment::mentionableUsers()))
        ->toBe('Over to <span class="mention-highlight">@Sam Rivera</span> and <span class="mention-highlight">@Quinn Alder</span> — not @Samuel.');
});

it('highlights on the RFQ\'s thread, and says anyone can be mentioned', function () {
    staffNamed('Quinn Alder', 'General Manager');
    $viewer = staffNamed('Olivia Park', 'Senior Operations');
    $rfq = Rfq::factory()->create();
    $rfq->comments()->create(['user_id' => $viewer->id, 'body' => '@Quinn Alder please see']);

    test()->actingAs($viewer)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('<span class="mention-highlight">@Quinn Alder</span> please see', false)
        ->assertSee('Write a comment... (everyone on this RFQ gets it in Messages — @ to mention someone else)', false);
});

it('keeps the names safe in the page', function () {
    staffNamed('</script><b>Eve</b>', 'Sourcing');
    $viewer = staffNamed('Olivia Park', 'Senior Operations');

    $html = test()->actingAs($viewer)->get(route('admin.rfqs.show', Rfq::factory()->create()))->assertOk()->getContent();

    expect($html)->not->toContain('</script><b>Eve</b>')
        ->and(collect(mentionListOn(Rfq::factory()->create(), $viewer))->pluck('name'))->toContain('</script><b>Eve</b>');
});
