<?php

use App\Models\Rfq;
use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
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

it('turns down a new WC number that isn\'t four digits while it\'s on', function (string $wcNumber) {
    requireWcFormat();

    createRfqWithWc($wcNumber)->assertSessionHasErrorsIn('create', [
        'wc_number' => 'Type the WC number\'s four digits, like 0123 — WC is added for you.',
    ]);

    expect(Rfq::count())->toBe(0);
})->with([
    'three digits' => ['123'],
    'five digits' => ['12345'],
    'WC and three digits' => ['WC123'],
    'other letters' => ['AB1234'],
    'a letter among the digits' => ['12A4'],
    'a dash' => ['WC-1234'],
]);

it('takes the four digits while it\'s on, saving them with WC in front', function (string $typed, string $saved) {
    requireWcFormat();

    createRfqWithWc($typed)->assertSessionHasNoErrors();

    expect(Rfq::sole()->wc_number)->toBe($saved);
})->with([
    'the digits, as the form sends them' => ['0123', 'WC0123'],
    'with spaces around them' => [' 0456 ', 'WC0456'],
    // Sent whole, from anywhere but the form: still the same.
    'WC and the digits' => ['WC0789', 'WC0789'],
    'in small letters' => ['wc0321', 'WC0321'],
]);

it('fixes WC in front of the Add RFQ form\'s field while it\'s on, taking just the digits', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertDontSee('placeholder="WC0000"', false)
        ->assertDontSee('js-wc-digits', false);

    requireWcFormat();

    $html = test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()
        ->assertSee('Type the four digits — it\'s saved as WC0123.', false)
        // An Edit has the whole number: it can keep an older one.
        ->assertSeeInOrder(['id="edit-wc_number"', 'placeholder="WC0000"', 'id="edit-rfq_number"'], false)
        ->getContent();

    $add = Str::betweenFirst($html, '<label for="create-wc_number"', 'id="create-rfq_number"');
    expect($add)->toContain('<span class="input-group-text fw-semibold">WC</span>')
        ->toContain('placeholder="0000" inputmode="numeric" pattern="[0-9]{4}"')
        ->toContain('js-wc-digits')
        ->and(substr_count($html, 'js-wc-digits'))->toBe(1);
});

it('brings the digits back alone after a refused Add', function () {
    requireWcFormat();
    Permission::findOrCreate('rfqs.create');

    $html = test()->followingRedirects()
        ->actingAs(userWithRole('Business Development')->givePermissionTo('rfqs.create'))
        ->from(route('admin.rfqs.index'))
        ->post(route('admin.rfqs.store'), ['wc_number' => 'WC12', 'priority_level' => 'High', 'number_of_items' => 3, 'subject' => 'Lobby lighting'])
        ->assertOk()
        ->getContent();

    expect(Str::betweenFirst($html, 'id="create-wc_number"', '>'))
        ->toContain('is-invalid')
        ->toContain('value="12"');
    expect($html)->toContain('Type the WC number&#039;s four digits, like 0123 — WC is added for you.');
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
