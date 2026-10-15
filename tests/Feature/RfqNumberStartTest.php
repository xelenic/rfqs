<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Someone in Business Development who can create RFQs.
 */
function rfqCreator(): User
{
    Permission::findOrCreate('rfqs.create');

    return userWithRole('Business Development')->givePermissionTo('rfqs.create');
}

/**
 * $count RFQs created through the form, in turn — their numbers.
 *
 * @return array<int, string>
 */
function createRfqs(int $count): array
{
    $creator = rfqCreator();

    foreach (range(1, $count) as $i) {
        test()->actingAs($creator)->post(route('admin.rfqs.store'), [
            'wc_number' => 'WC'.(5000 + $i),
            'priority_level' => 'Medium',
            'number_of_items' => 3,
            'subject' => "Job {$i}",
        ])->assertSessionHasNoErrors();
    }

    return Rfq::query()->orderBy('id')->pluck('rfq_number')->all();
}

function saveRfqNumberStart(mixed $start, ?User $admin = null): TestResponse
{
    return test()->actingAs($admin ?? userWithRole('Admin'))->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.rfq-numbers'), ['start' => $start]);
}

it('starts at RFQ1001 until an Admin says otherwise', function () {
    expect(Setting::rfqNumberStart())->toBe(1001)
        ->and(createRfqs(2))->toBe(['RFQ1001', 'RFQ1002']);
});

it('gives the next RFQ the number it\'s set to start from, and counts on from there', function () {
    saveRfqNumberStart('RFQ1670')
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'New RFQs start from RFQ1670.')
        ->assertSessionHas('settings_tab', 'rfq-numbers');

    expect(Setting::rfqNumberStart())->toBe(1670)
        ->and(createRfqs(3))->toBe(['RFQ1670', 'RFQ1671', 'RFQ1672']);
});

it('takes the number with or without RFQ, in any case', function (string $typed) {
    saveRfqNumberStart($typed)->assertSessionHasNoErrors();

    expect(Rfq::nextRfqNumber())->toBe('RFQ1670');
})->with(['RFQ1670', 'rfq1670', '1670', ' RFQ1670 ']);

it('never gives a number again — starting below one already used carries on after it', function () {
    Rfq::factory()->create(['rfq_number' => 'RFQ1700']);

    saveRfqNumberStart('RFQ1670')
        ->assertSessionHas('status', 'Saved — but RFQ1700 is already taken, so the next RFQ will be RFQ1701.');

    expect(createRfqs(1))->toBe(['RFQ1700', 'RFQ1701']);
});

it('jumps ahead past the RFQs there are', function () {
    createRfqs(2);
    saveRfqNumberStart('RFQ1670');

    expect(createRfqs(1))->toBe(['RFQ1001', 'RFQ1002', 'RFQ1670']);
});

it('shows the next RFQ number on the tab, and the highest given', function () {
    Rfq::factory()->create(['rfq_number' => 'RFQ1050']);
    Setting::putRfqNumberStart(1670);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'rfq-numbers']))->assertOk()
        ->assertSee('action="'.route('admin.settings.rfq-numbers').'"', false)
        ->assertSee('value="RFQ1670"', false)
        ->assertSeeInOrder(['Next RFQ', 'RFQ1670', 'Highest given so far', 'RFQ1050']);

    // And on the new RFQ form.
    test()->actingAs(rfqCreator())->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('value="RFQ1670" disabled readonly', false);
});

it('refuses a start that isn\'t an RFQ number, keeping the one it had', function (string $typed, string $message) {
    saveRfqNumberStart($typed)->assertSessionHasErrorsIn('rfq_numbers', ['start' => $message]);

    expect(Setting::rfqNumberStart())->toBe(1001);
})->with([
    'nothing' => ['', 'Give the RFQ number to start from, like RFQ1670.'],
    'letters' => ['WC1670', 'Give it as RFQ and a number, like RFQ1670 — or just 1670.'],
    'a gap' => ['RFQ 16', 'Give it as RFQ and a number, like RFQ1670 — or just 1670.'],
    'zero' => ['RFQ0', 'Give a number from RFQ0001 to RFQ99999999.'],
    'too big' => ['RFQ123456789', 'Give a number from RFQ0001 to RFQ99999999.'],
]);

it('keeps it to an Admin', function () {
    saveRfqNumberStart('RFQ1670', userWithRole('Senior Operations'))->assertForbidden();

    expect(Setting::rfqNumberStart())->toBe(1001);
});
