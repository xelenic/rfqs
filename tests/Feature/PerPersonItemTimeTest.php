<?php

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
function worker(string $name, string $role): User
{
    return tap(userWithRole($role))->update(['name' => $name]);
}

/**
 * RFQ9801's one part with Data Entry: 10 line items to add, given to $to by
 * name — or to anyone in Data Entry.
 */
function withTenLineItems(?User $to = null): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9801', 'subject' => 'Hoses']), [1 => worker('Riley Chen', 'Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, $to, 10);

    return $rfq->refresh();
}

/**
 * RFQ9802's one part at GM Assistant: the Head finalized 30 line items.
 */
function withThirtyFinalized(): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9802', 'priority_level' => 'High']), [1 => worker('Riley Chen', 'Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, null, 24);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'), 'Entered', 22);
    $rfq->refresh()->finalizePart(1, 22);
    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'), 23);
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'), 30);

    return $rfq->refresh();
}

it('lets an Admin give people in Data Entry and GM Assistant their own time per item on Settings', function () {
    $admin = userWithRole('Admin');
    $dana = worker('Dana Entry', 'Data Entry');
    $casey = worker('Casey Brooks', 'Data Entry');
    $gail = worker('Gail Assist', 'GM Assistant');
    $riley = worker('Riley Chen', 'Sourcing');

    $html = test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'data-entry-targets']))->assertOk()
        ->assertSee('Time per item, person by person')
        ->getContent();
    expect(Str::betweenFirst($html, 'id="data-entry-per-item-people"', '</form>'))
        ->toContain('data-per-item-person="'.$dana->id.'"')
        ->toContain('data-per-item-person="'.$casey->id.'"')
        ->not->toContain('data-per-item-person="'.$riley->id.'"')
        ->toContain('placeholder="2"');

    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item-people'), ['people' => [$dana->id => '3', $casey->id => '', $riley->id => '5']])
        ->assertSessionHas('status', 'Saved — 1 person takes their own time per item.');
    test()->actingAs($admin)->patch(route('admin.settings.gm-assistant-per-item-people'), ['people' => [$gail->id => '1.5']])
        ->assertSessionHasNoErrors();

    expect(Setting::perItemPeople('data_entry'))->toBe([$dana->id => 180])
        ->and(Setting::perItemPeople('gm_assistant'))->toBe([$gail->id => 90])
        ->and(Setting::dataEntryPerItemFor($dana->id))->toBe(['enabled' => false, 'seconds' => 180, 'own' => true])
        ->and(Setting::dataEntryPerItemFor($casey->id))->toBe(['enabled' => false, 'seconds' => 120, 'own' => false]);

    expect(Str::betweenFirst(test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'gm-assistant-targets']))->getContent(), 'data-per-item-person="'.$gail->id.'"', '</tr>'))
        ->toContain('value="1.5"');

    // Emptied, it's the company's again.
    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item-people'), ['people' => [$dana->id => '']])
        ->assertSessionHas('status', 'Saved — everyone in Data Entry takes the time per item above.');
    expect(Setting::perItemPeople('data_entry'))->toBe([]);
});

it('refuses a time out of range, and keeps it to an Admin', function () {
    $dana = worker('Dana Entry', 'Data Entry');

    test()->actingAs(userWithRole('Admin'))->from(route('admin.settings.edit', ['tab' => 'data-entry-targets']))
        ->patch(route('admin.settings.data-entry-per-item-people'), ['people' => [$dana->id => '0.2']])
        ->assertSessionHasErrorsIn('data_entry_per_item_people', ['people.'.$dana->id]);
    expect(Setting::perItemPeople('data_entry'))->toBe([]);

    test()->actingAs(userWithRole('Senior Operations'))->patch(route('admin.settings.data-entry-per-item-people'), ['people' => [$dana->id => '3']])->assertForbidden();
});

it('times a Data Entry part by its person\'s own time — given to them by name, or once they start one left to anyone', function () {
    $dana = worker('Dana Entry', 'Data Entry');
    $casey = worker('Casey Brooks', 'Data Entry');
    Setting::putPerItemPeople('data_entry', [$dana->id => 180]);

    // 10 line items × Dana's 3 min; × the company's 2 min for Casey.
    expect(withTenLineItems($dana)->dataEntryTargetSeconds(1))->toBe(1800);
    Rfq::query()->delete();
    expect(withTenLineItems($casey)->dataEntryTargetSeconds(1))->toBe(1200);
    Rfq::query()->delete();

    $anyone = withTenLineItems();
    expect($anyone->dataEntryTargetSeconds(1))->toBe(1200);
    startDataEntryOn($anyone, 1, $dana);
    expect($anyone->refresh()->dataEntryTargetSeconds(1))->toBe(1800)
        ->and($anyone->dataEntryTargetBreakdown(1))->toBe(['items' => 10, 'perItem' => 180, 'seconds' => 1800, 'source' => 'line_items']);

    test()->actingAs($dana)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee("10 line items × 3 min (Dana Entry's own) = 30m");
});

it('times a GM Assistant part by its starter\'s own time, and says so on hover', function () {
    $gail = worker('Gail Assist', 'GM Assistant');
    $harper = worker('Harper Collins', 'GM Assistant');
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    Setting::putPerItemPeople('gm_assistant', [$gail->id => 60]);
    $rfq = withThirtyFinalized();

    // Till someone starts it, the company's: 30 × 2 min.
    expect($rfq->gmAssistantTargetSeconds(1))->toBe(3600);

    startGmAssistantOn($rfq, 1, $gail);
    expect($rfq->refresh()->gmAssistantTargetSeconds(1))->toBe(1800)
        ->and($rfq->gmAssistantTimeExplained(1)['perItemOwnOf'])->toBe('Gail Assist');

    $html = test()->actingAs($gail)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    expect(Str::squish(html_entity_decode(strip_tags(Str::betweenFirst($html, '<template id="gma-time-'.$rfq->id.'-p1">', '</template>')), ENT_QUOTES)))
        ->toContain('Target = 30 Finalized Line Items × 1 min a line item = 30m')
        ->toContain("Time a line item takes 1 min Gail Assist's own time — Settings → GM Assistant Time, person by person");

    // Someone without their own takes the company's.
    $rfq->assigneeForPart(1)->pivot->update(['gm_assistant_started_by' => $harper->id]);
    expect($rfq->refresh()->gmAssistantTargetSeconds(1))->toBe(3600)
        ->and($rfq->gmAssistantTimeExplained(1)['perItemOwnOf'])->toBeNull();
});
