<?php

use App\Models\Rfq;
use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page and Settings look up each role's members.
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Turns the WC0000 check on, as an Admin would on Settings.
 */
function requireWcFormat(): void
{
    Setting::put('wc_format_required', '1');
}

/**
 * Business Development creating an RFQ with $wcNumber.
 */
function createRfqWithWc(string $wcNumber): TestResponse
{
    Permission::findOrCreate('rfqs.create');

    return test()->actingAs(userWithRole('Business Development')->givePermissionTo('rfqs.create'))
        ->post(route('admin.rfqs.store'), [
            'wc_number' => $wcNumber,
            'priority_level' => 'High',
            'number_of_items' => 3,
            'subject' => 'Replace the lobby lighting',
        ]);
}

/**
 * Admin editing $rfq's WC number to $wcNumber, the rest as it was.
 */
function editRfqWc(Rfq $rfq, string $wcNumber): TestResponse
{
    return test()->actingAs(userWithRole('Admin'))->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $wcNumber,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => $rfq->priority_level,
        'number_of_items' => $rfq->number_of_items,
        'status' => $rfq->status,
        'subject' => $rfq->subject,
    ]);
}

// ---- the setting -----------------------------------------------------------

it('gives Admin a WC Validation tab, off to begin with', function () {
    expect(Setting::wcFormatRequired())->toBeFalse();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'wc-validation']))->assertOk()
        ->assertSee('id="settings-tab-wc-validation"', false)
        ->assertSee('Require the WC0000 format')
        ->assertDontSee('id="wc-validation-enabled" value="1" checked', false);

    test()->actingAs(userWithRole('Business Development'))->get(route('admin.settings.edit'))->assertOk()
        ->assertDontSee('id="settings-tab-wc-validation"', false);
});

it('lets Admin turn it on and off, and nobody else', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->patch(route('admin.settings.wc-validation'), ['enabled' => '1'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'WC validation turned on.')
        ->assertSessionHas('settings_tab', 'wc-validation');
    expect(Setting::wcFormatRequired())->toBeTrue();

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'wc-validation']))
        ->assertSee('id="wc-validation-enabled" value="1" checked', false);

    test()->actingAs(userWithRole('Business Development'))->patch(route('admin.settings.wc-validation'), ['enabled' => '0'])
        ->assertForbidden();
    expect(Setting::wcFormatRequired())->toBeTrue();

    // The switch off sends just the hidden 0.
    test()->actingAs($admin)->patch(route('admin.settings.wc-validation'), ['enabled' => '0'])
        ->assertSessionHas('status', 'WC validation turned off.');
    expect(Setting::wcFormatRequired())->toBeFalse();
});

// ---- creating --------------------------------------------------------------

it('takes any WC number while it\'s off', function (string $wcNumber) {
    createRfqWithWc($wcNumber)->assertSessionHasNoErrors();

    expect(Rfq::sole()->wc_number)->toBe($wcNumber);
})->with(['WC-77', '12345', 'Site 4']);

it('turns down a new WC number that isn\'t WC and four digits while it\'s on', function (string $wcNumber) {
    requireWcFormat();

    createRfqWithWc($wcNumber)->assertSessionHasErrorsIn('create', [
        'wc_number' => 'Use the WC0000 format — WC and four digits, like WC0123.',
    ]);

    expect(Rfq::count())->toBe(0);
})->with([
    'three digits' => ['WC123'],
    'five digits' => ['WC12345'],
    'other letters' => ['AB1234'],
    'no letters' => ['1234'],
    'a letter among the digits' => ['WC12A4'],
    'a dash' => ['WC-1234'],
]);

it('takes WC and four digits while it\'s on — in small letters too, saved in capitals', function (string $typed, string $saved) {
    requireWcFormat();

    createRfqWithWc($typed)->assertSessionHasNoErrors();

    expect(Rfq::sole()->wc_number)->toBe($saved);
})->with([
    'as it should be' => ['WC0123', 'WC0123'],
    'in small letters' => ['wc0456', 'WC0456'],
    'with spaces around it' => ['  WC0789 ', 'WC0789'],
]);

it('shows the format on the Add RFQ form only while it\'s on — and checks it there as it\'s typed', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertDontSee('placeholder="WC0000"', false)
        ->assertDontSee('pattern="[Ww][Cc][0-9]{4}"', false);

    requireWcFormat();

    test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertSee('placeholder="WC0000"', false)
        ->assertSee('WC and four digits, like WC0123.')
        // Only the Add form's is checked in the browser: an Edit can keep an older number.
        ->assertSeeInOrder(['id="create-wc_number"', 'pattern="[Ww][Cc][0-9]{4}"', 'id="create-rfq_number"'], false)
        ->assertSeeInOrder(['id="edit-wc_number"', 'placeholder="WC0000"', 'id="edit-rfq_number"'], false);

    expect(substr_count(test()->actingAs($admin)->get(route('admin.rfqs.index'))->getContent(), 'pattern="[Ww][Cc][0-9]{4}"'))->toBe(1);
});

// ---- editing ---------------------------------------------------------------

it('lets an older RFQ keep its WC number through an edit, and checks a changed one', function () {
    $rfq = Rfq::factory()->create(['wc_number' => 'Site 4']);
    requireWcFormat();

    // Kept as it was.
    editRfqWc($rfq, 'Site 4')->assertSessionHasNoErrors();
    expect($rfq->refresh()->wc_number)->toBe('Site 4');

    // Changed — to something that isn't the format…
    editRfqWc($rfq, 'Site 5')->assertSessionHasErrorsIn('edit', ['wc_number']);
    expect($rfq->refresh()->wc_number)->toBe('Site 4');

    // …and to something that is.
    editRfqWc($rfq, 'wc0042')->assertSessionHasNoErrors();
    expect($rfq->refresh()->wc_number)->toBe('WC0042');
});
