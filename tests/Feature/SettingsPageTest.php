<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * The session a close leaves behind for the next page.
 *
 * @return array<string, mixed>
 */
function settingsCelebration(): array
{
    return ['celebrate' => ['title' => 'Closed', 'label' => 'RFQ1001', 'message' => 'Well done', 'grand' => true, 'url' => '/admin']];
}

// ---- the page --------------------------------------------------------------

it('is for signed-in people only', function () {
    test()->get(route('admin.settings.edit'))->assertRedirect(route('login'));
    test()->patch(route('admin.settings.profile'), ['name' => 'X', 'email' => 'x@example.test'])->assertRedirect(route('login'));
    test()->put(route('admin.settings.password'))->assertRedirect(route('login'));
});

it('is open to every role, with the Application settings for an Admin alone', function (string $role, bool $isAdmin) {
    $response = test()->actingAs(userWithRole($role))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Profile')
        ->assertSee('Change password')
        ->assertSee('Update pages automatically');

    $isAdmin
        ? $response->assertSee('Company name')
        : $response->assertDontSee('Company name');
})->with([
    'Admin' => ['Admin', true],
    'Business Development' => ['Business Development', false],
    'Senior Operations' => ['Senior Operations', false],
    'Sourcing' => ['Sourcing', false],
    'Data Entry' => ['Data Entry', false],
    'General Manager' => ['General Manager', false],
]);

it('is reached from the sidebar and the account menu, and is not one of the live pages', function () {
    $html = test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))->assertOk()->getContent();

    expect(substr_count($html, 'href="'.route('admin.settings.edit').'"'))->toBe(2);

    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.settings.edit'))
        ->assertSee('id="live-main" data-live="off"', false);
});

// ---- profile ---------------------------------------------------------------

it('changes your name and email', function () {
    $user = userWithRole('Sourcing');

    test()->actingAs($user)
        ->patch(route('admin.settings.profile'), ['name' => 'Riley Chen', 'email' => 'riley@example.test'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Profile updated.');

    expect($user->refresh())->name->toBe('Riley Chen')->email->toBe('riley@example.test');
});

it('lets you keep your own email, but not take somebody else\'s', function () {
    $user = userWithRole('Sourcing');
    $other = User::factory()->create(['email' => 'taken@example.test']);

    test()->actingAs($user)
        ->patch(route('admin.settings.profile'), ['name' => 'Same Email', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    test()->actingAs($user)
        ->patch(route('admin.settings.profile'), ['name' => 'Someone Else', 'email' => $other->email])
        ->assertSessionHasErrorsIn('profile', 'email');

    expect($user->refresh()->name)->toBe('Same Email');
});

it('needs a name and a real email', function (array $input, string $field) {
    test()->actingAs(userWithRole('Sourcing'))
        ->patch(route('admin.settings.profile'), $input)
        ->assertSessionHasErrorsIn('profile', $field);
})->with([
    'no name' => [['name' => '', 'email' => 'a@example.test'], 'name'],
    'no email' => [['name' => 'A', 'email' => ''], 'email'],
    'not an email' => [['name' => 'A', 'email' => 'nope'], 'email'],
]);

// ---- password --------------------------------------------------------------

it('changes your password once you\'ve given the current one', function () {
    $user = userWithRole('Sourcing');
    $user->update(['password' => 'old-password']);

    test()->actingAs($user)
        ->put(route('admin.settings.password'), ['current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Password changed.');

    expect(Hash::check('new-password-1', $user->refresh()->password))->toBeTrue()
        ->and(Hash::check('old-password', $user->password))->toBeFalse();
});

it('does not change it on a wrong current password, a mismatch, a short one or the same one again', function (array $input, string $field) {
    $user = userWithRole('Sourcing');
    $user->update(['password' => 'old-password']);

    test()->actingAs($user)
        ->put(route('admin.settings.password'), $input)
        ->assertSessionHasErrorsIn('password', $field);

    expect(Hash::check('old-password', $user->refresh()->password))->toBeTrue();
})->with([
    'wrong current' => [['current_password' => 'not-it', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'], 'current_password'],
    'mismatch' => [['current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'different-1'], 'password'],
    'too short' => [['current_password' => 'old-password', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'the same' => [['current_password' => 'old-password', 'password' => 'old-password', 'password_confirmation' => 'old-password'], 'password'],
]);

// ---- preferences -----------------------------------------------------------

it('starts with everything on', function () {
    $user = User::factory()->create();

    expect($user->preference('live_updates'))->toBeTrue()
        ->and($user->preference('celebrations'))->toBeTrue()
        ->and($user->preference('something_else'))->toBeNull();
});

it('stops the live updates for someone who turns them off, and for them alone', function () {
    $riley = userWithRole('Sourcing');
    $sam = userWithRole('Sourcing');

    test()->actingAs($riley)
        ->patch(route('admin.settings.preferences'), ['live_updates' => '0'])
        ->assertSessionHas('status', 'Preferences saved.');

    expect($riley->refresh()->preference('live_updates'))->toBeFalse();

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee('name="live-version"', false)
        ->assertDontSee('name="live-pulse"', false)
        ->assertDontSee('id="liveIndicator"', false);

    test()->actingAs($sam)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('name="live-version"', false)
        ->assertSee('id="liveIndicator"', false);
});

it('brings the live updates back when they\'re turned on again', function () {
    $user = userWithRole('Sourcing');
    $user->preferences = ['live_updates' => false];
    $user->save();

    test()->actingAs($user)->patch(route('admin.settings.preferences'), ['live_updates' => '1']);

    test()->actingAs($user->refresh())->get(route('admin.dashboard'))->assertSee('name="live-pulse"', false);
});

it('lets those who close RFQs turn the fireworks off', function () {
    $closer = userWithRole('Business Development');

    test()->actingAs($closer)->withSession(settingsCelebration())->get(route('admin.dashboard'))
        ->assertSee('id="celebration"', false);

    test()->actingAs($closer)->get(route('admin.settings.edit'))->assertSee('Celebrate closed RFQs');
    test()->actingAs($closer)->patch(route('admin.settings.preferences'), ['live_updates' => '1', 'celebrations' => '0']);

    test()->actingAs($closer->refresh())->withSession(settingsCelebration())->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('id="celebration"', false);
});

it('offers the fireworks only to those who close RFQs, and leaves everyone else\'s alone', function () {
    $sourcing = userWithRole('Sourcing');

    test()->actingAs($sourcing)->get(route('admin.settings.edit'))->assertDontSee('Celebrate closed RFQs');

    test()->actingAs($sourcing)->patch(route('admin.settings.preferences'), ['live_updates' => '1', 'celebrations' => '0']);

    expect($sourcing->refresh()->preferences)->toBe(['live_updates' => true]);
});

// ---- application (Admin) ---------------------------------------------------

it('lets an Admin rename the panel, from the tab title to the sign-in page', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)
        ->patch(route('admin.settings.application'), ['company_name' => 'Lanka Facilities', 'live_interval' => 3])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Application settings saved.');

    test()->actingAs($admin)->get(route('admin.dashboard'))
        ->assertSee('Lanka Facilities Admin</title>', false)
        ->assertSee('alt="Lanka Facilities"', false)
        ->assertSee('Lanka Facilities')
        ->assertDontSee(config('app.name').' Admin</title>', false);

    auth()->logout();
    test()->get(route('login'))->assertSee('Lanka Facilities Admin', false);
});

it('goes back to the configured name when the company name is emptied', function () {
    Setting::put('company_name', 'Lanka Facilities');

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.application'), ['company_name' => '', 'live_interval' => 3])
        ->assertSessionHasNoErrors();

    expect(Setting::companyName())->toBe(config('app.name'))
        ->and(Setting::query()->where('key', 'company_name')->exists())->toBeFalse();
});

it('sets how often the pages check for changes', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.dashboard'))->assertSee('<meta name="live-interval" content="3000">', false);

    test()->actingAs($admin)->patch(route('admin.settings.application'), ['company_name' => '', 'live_interval' => 8]);

    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))->assertSee('<meta name="live-interval" content="8000">', false);
});

it('keeps the pace and the name within bounds', function (array $input, string $field) {
    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.application'), $input)
        ->assertSessionHasErrorsIn('application', $field);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'too quick' => [['company_name' => '', 'live_interval' => 1], 'live_interval'],
    'too slow' => [['company_name' => '', 'live_interval' => 61], 'live_interval'],
    'not a number' => [['company_name' => '', 'live_interval' => 'often'], 'live_interval'],
    'missing' => [['company_name' => ''], 'live_interval'],
    'a very long name' => [['company_name' => str_repeat('a', 81), 'live_interval' => 3], 'company_name'],
]);

it('refuses the application settings to anyone but an Admin', function (string $role) {
    test()->actingAs(userWithRole($role))
        ->patch(route('admin.settings.application'), ['company_name' => 'Hijacked', 'live_interval' => 2])
        ->assertForbidden();

    expect(Setting::query()->count())->toBe(0);
})->with(['Business Development', 'Senior Operations', 'Sourcing', 'General Manager']);

// ---- the settings themselves -----------------------------------------------

it('reads a setting, or its default, and forgets it when put back', function () {
    expect(Setting::get('anything'))->toBeNull()
        ->and(Setting::get('anything', 'fallback'))->toBe('fallback');

    Setting::put('anything', 'something');
    expect(Setting::get('anything'))->toBe('something');

    Setting::put('anything', 'something else');
    expect(Setting::get('anything'))->toBe('something else')
        ->and(Setting::query()->where('key', 'anything')->count())->toBe(1);

    Setting::put('anything', null);
    expect(Setting::get('anything'))->toBeNull();
});

it('keeps the check interval within reason whatever was stored', function (string $stored, int $seconds) {
    Setting::put('live_interval', $stored);

    expect(Setting::liveIntervalSeconds())->toBe($seconds);
})->with([
    'in range' => ['10', 10],
    'too small' => ['0', 2],
    'too big' => ['9999', 60],
    'rubbish' => ['soon', 2],
]);

it('starts at three seconds', function () {
    expect(Setting::liveIntervalSeconds())->toBe(3);
});
