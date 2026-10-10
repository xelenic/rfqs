<?php

use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    Role::findOrCreate('Admin')->givePermissionTo(Permission::all());
    Role::findOrCreate('HR Manager')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
    Role::findOrCreate('Data Entry');
});

/**
 * Someone holding $role, named $name.
 */
function personAs(string $role, string $name): User
{
    return User::factory()->create(['name' => $name])->assignRole($role);
}

/**
 * $by adds Morgan, as $roles.
 *
 * @param  array<int, string>  $roles
 */
function addMorgan(User $by, array $roles = ['Data Entry']): TestResponse
{
    return test()->actingAs($by)->from(route('admin.users.index'))
        ->post(route('admin.users.store'), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'roles' => $roles]);
}

/**
 * The set-password link Morgan was last emailed.
 */
function morgansLink(): string
{
    $link = null;

    Notification::assertSentTo(User::query()->where('email', 'morgan@company.lk')->sole(), AccountCreated::class, function (AccountCreated $mail, array $channels, User $morgan) use (&$link) {
        $link = $mail->url($morgan);

        return true;
    });

    return $link;
}

it('lets HR Manager add someone — no password asked — and emails them a link to set their own', function () {
    Notification::fake();
    $alex = personAs('HR Manager', 'Alex Silva');

    test()->actingAs($alex)->get(route('admin.users.index'))->assertOk()
        ->assertSee('Add user')
        ->assertDontSee('id="create-password"', false)
        ->assertSee('to set their own password — it works for 7 days');

    addMorgan($alex)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('status', 'User created — an email is on its way to morgan@company.lk to set their password.');

    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();
    expect($morgan->hasRole('Data Entry'))->toBeTrue();

    Notification::assertSentTo($morgan, AccountCreated::class, function (AccountCreated $mail, array $channels) use ($morgan) {
        $message = $mail->toMail($morgan);

        return $channels === ['mail']
            && $message->subject === 'Your '.Setting::companyName().' account is ready'
            && in_array('Alex Silva has created an account for you as Data Entry.', $message->introLines, true)
            && $message->actionText === 'Set your password'
            && str_starts_with($message->actionUrl, route('password.set', ['token' => $mail->token]))
            && Password::broker('new_users')->tokenExists($morgan, $mail->token);
    });
});

it('never lets anyone but an Admin make an Admin', function () {
    Notification::fake();
    $alex = personAs('HR Manager', 'Alex Silva');

    expect(test()->actingAs($alex)->get(route('admin.users.index'))->getContent())
        ->not->toContain('value="Admin"')
        ->toContain('value="Data Entry"');

    addMorgan($alex, ['Admin'])->assertSessionHasErrorsIn('create', ['roles.0' => 'Pick from the roles listed.']);
    expect(User::query()->where('email', 'morgan@company.lk')->exists())->toBeFalse();

    addMorgan(personAs('Admin', 'Root'), ['Admin'])->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'morgan@company.lk')->sole()->hasRole('Admin'))->toBeTrue();
});

it('keeps people\'s details to Admin and HR Manager', function () {
    $dataEntry = personAs('Data Entry', 'Sam');

    test()->actingAs($dataEntry)->get(route('admin.users.index'))->assertForbidden();
    addMorgan($dataEntry)->assertForbidden();
    test()->actingAs($dataEntry)->put(route('admin.users.update', $dataEntry), ['name' => 'X', 'email' => 'x@y.lk'])->assertForbidden();

    // HR Manager changes someone's details — deleting stays Admin's.
    $alex = personAs('HR Manager', 'Alex Silva');
    test()->actingAs($alex)->put(route('admin.users.update', $dataEntry), ['name' => 'Sam Fernando', 'email' => 'sam@company.lk', 'roles' => ['Data Entry']])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'User updated successfully.');
    expect($dataEntry->refresh())->name->toBe('Sam Fernando')->email->toBe('sam@company.lk');

    test()->actingAs($alex)->delete(route('admin.users.destroy', $dataEntry))->assertForbidden();
});

it('never lets HR Manager touch an Admin\'s account, or set a password', function () {
    Notification::fake();
    $alex = personAs('HR Manager', 'Alex Silva');
    $root = personAs('Admin', 'Root');
    $sam = personAs('Data Entry', 'Sam');

    $row = Str::betweenFirst(test()->actingAs($alex)->get(route('admin.users.index'))->getContent(), '<td>'.$root->email.'</td>', '</tr>');
    expect($row)->not->toContain('js-edit-user')->not->toContain('password-link');

    test()->actingAs($alex)->put(route('admin.users.update', $root), ['name' => 'Root', 'email' => 'alex@company.lk'])->assertForbidden();
    test()->actingAs($alex)->post(route('admin.users.password-link', $root))->assertForbidden();
    expect($root->refresh()->email)->not->toBe('alex@company.lk');
    Notification::assertNothingSent();

    // No password field for them; one sent anyway is refused.
    expect(test()->actingAs($alex)->get(route('admin.users.index'))->getContent())->not->toContain('id="edit-password"');
    test()->actingAs($alex)->put(route('admin.users.update', $sam), ['name' => 'Sam', 'email' => $sam->email, 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026'])
        ->assertSessionHasErrorsIn('edit', ['password' => 'Only an Admin can set someone\'s password — send them a link instead.']);

    // An Admin can do all of it.
    test()->actingAs($root)->put(route('admin.users.update', $sam), ['name' => 'Sam', 'email' => $sam->email, 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('quotes-2026', $sam->refresh()->password))->toBeTrue();
});

it('sets their password from the link, once, and signs them in', function () {
    Notification::fake();
    addMorgan(personAs('HR Manager', 'Alex Silva'));
    $link = morgansLink();
    $token = basename(parse_url($link, PHP_URL_PATH));
    Auth::logout();

    test()->get($link)->assertOk()
        ->assertSee('Set your password')
        ->assertSee('value="morgan@company.lk"', false);

    test()->post(route('password.set.store'), ['token' => $token, 'email' => 'morgan@company.lk', 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026'])
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('status', 'Your password is set — welcome aboard.');

    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();
    expect(Auth::id())->toBe($morgan->id)
        ->and(Hash::check('quotes-2026', $morgan->password))->toBeTrue()
        ->and($morgan->email_verified_at)->not->toBeNull();

    // Only the once.
    Auth::logout();
    test()->from($link)->post(route('password.set.store'), ['token' => $token, 'email' => 'morgan@company.lk', 'password' => 'another-one', 'password_confirmation' => 'another-one'])
        ->assertRedirect($link)
        ->assertSessionHasErrors(['email' => 'This link has expired or has already been used — ask HR for a new one.']);
});

it('stops the link working after 7 days, and sends a fresh one that replaces it', function () {
    Notification::fake();
    $alex = personAs('HR Manager', 'Alex Silva');
    addMorgan($alex);
    $first = basename(parse_url(morgansLink(), PHP_URL_PATH));
    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();

    test()->travel(8)->days();
    Auth::logout();
    test()->post(route('password.set.store'), ['token' => $first, 'email' => 'morgan@company.lk', 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026'])
        ->assertSessionHasErrors('email');

    test()->actingAs($alex)->post(route('admin.users.password-link', $morgan))
        ->assertSessionHas('status', 'A fresh link to set their password is on its way to morgan@company.lk.');
    Notification::assertSentToTimes($morgan, AccountCreated::class, 2);

    expect(Password::broker('new_users')->tokenExists($morgan, $first))->toBeFalse();
});

it('keeps the account when the email can\'t be sent, and says so', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

    addMorgan(personAs('HR Manager', 'Alex Silva'))
        ->assertSessionHas('error', 'The email to morgan@company.lk couldn\'t be sent — check the mail settings, then use Send link to try again.');

    expect(User::query()->where('email', 'morgan@company.lk')->exists())->toBeTrue();
});

it('lets an Admin set a password instead — no email — or send the link, their choice', function () {
    Notification::fake();
    $root = personAs('Admin', 'Root');

    expect(test()->actingAs($root)->get(route('admin.users.index'))->getContent())
        ->toContain('name="sign_in" id="create-sign-in-email" value="email_link"')
        ->toContain('name="sign_in" id="create-sign-in-password" value="password"');

    test()->actingAs($root)->post(route('admin.users.store'), [
        'name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'roles' => ['Data Entry'],
        'sign_in' => 'password', 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026',
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'User created — they can sign in with the password you set.');

    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();
    expect(Hash::check('quotes-2026', $morgan->password))->toBeTrue();
    Notification::assertNothingSent();

    // They sign in with it.
    Auth::logout();
    test()->post(route('login.store'), ['email' => 'morgan@company.lk', 'password' => 'quotes-2026'])->assertRedirect(route('admin.dashboard'));

    // The link's still the way by default.
    Auth::logout();
    test()->actingAs($root)->post(route('admin.users.store'), ['name' => 'Casey', 'email' => 'casey@company.lk', 'sign_in' => 'email_link', 'password' => 'ignored-1'])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo(User::query()->where('email', 'casey@company.lk')->sole(), AccountCreated::class);
});

it('needs a sound password when an Admin sets one, and only an Admin can', function (array $password, string $error) {
    Notification::fake();

    test()->actingAs(personAs('Admin', 'Root'))->from(route('admin.users.index'))
        ->post(route('admin.users.store'), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'sign_in' => 'password'] + $password)
        ->assertSessionHasErrorsIn('create', ['password' => $error]);

    test()->actingAs(personAs('HR Manager', 'Alex Silva'))
        ->post(route('admin.users.store'), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'sign_in' => 'password', 'password' => 'quotes-2026', 'password_confirmation' => 'quotes-2026'])
        ->assertSessionHasErrorsIn('create', ['sign_in' => 'Only an Admin can set a password for someone — email them a link instead.']);

    expect(User::query()->where('email', 'morgan@company.lk')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'none' => [[], 'The password field is required.'],
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'The password field must be at least 8 characters.'],
    'not matching' => [['password' => 'quotes-2026', 'password_confirmation' => 'quotes-2027'], 'The password field confirmation does not match.'],
]);

it('shows HR Manager no choice — the link it is', function () {
    expect(test()->actingAs(personAs('HR Manager', 'Alex Silva'))->get(route('admin.users.index'))->getContent())
        ->not->toContain('name="sign_in"')
        ->not->toContain('id="create-password"')
        ->toContain('to set their own password — it works for 7 days');
});
