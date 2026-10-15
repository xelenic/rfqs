<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Storage::fake(User::AVATAR_DISK);

    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Manager'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findByName('Admin')->givePermissionTo(Permission::all());
    Role::findByName('HR Manager')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
});

/**
 * $by puts up a photo for $person (themselves by default), from Settings.
 */
function putUpPhoto(User $by, ?User $person = null, ?UploadedFile $photo = null): TestResponse
{
    return test()->actingAs($by)->post(route('admin.users.photo.update', $person ?? $by), [
        'photo' => $photo ?? UploadedFile::fake()->image('me.jpg', 512, 512),
        'return_to' => 'settings',
    ]);
}

it('lets anyone put up their own photo on Settings → Profile, and shows it in the top bar', function () {
    $riley = userWithRole('Sourcing');

    test()->actingAs($riley)->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('action="'.route('admin.users.photo.update', $riley).'"', false)
        ->assertSee('Upload photo')
        ->assertSee('class="user-avatar">'.$riley->initial().'</span>', false);

    putUpPhoto($riley)
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Photo updated.')
        ->assertSessionHas('settings_tab', 'profile');

    $riley->refresh();
    Storage::disk(User::AVATAR_DISK)->assertExists($riley->avatar_path);

    expect($riley->avatar_path)->toStartWith('avatars/')
        ->and($riley->avatarUrl())->toStartWith(route('admin.users.photo', $riley).'?v=');

    test()->actingAs($riley)->get(route('admin.settings.edit'))
        ->assertSee('class="user-avatar has-photo"><img src="'.$riley->avatarUrl().'"', false)
        ->assertSee('class="settings-avatar settings-photo has-photo"><img src="'.$riley->avatarUrl().'"', false)
        ->assertSee('Change photo')
        ->assertSee('action="'.route('admin.users.photo.destroy', $riley).'"', false);
});

it('replaces the last photo, file and all — at a new address — and takes it down again', function () {
    $riley = userWithRole('Sourcing');

    putUpPhoto($riley);
    [$first, $firstUrl] = [$riley->refresh()->avatar_path, $riley->avatarUrl()];

    putUpPhoto($riley, photo: UploadedFile::fake()->image('again.png', 300, 300));
    $riley->refresh();

    Storage::disk(User::AVATAR_DISK)->assertMissing($first);
    Storage::disk(User::AVATAR_DISK)->assertExists($riley->avatar_path);
    expect($riley->avatarUrl())->not->toBe($firstUrl);

    $second = $riley->avatar_path;
    test()->actingAs($riley)->delete(route('admin.users.photo.destroy', $riley), ['return_to' => 'settings'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Photo removed.');

    Storage::disk(User::AVATAR_DISK)->assertMissing($second);
    expect($riley->refresh()->avatar_path)->toBeNull()
        ->and($riley->avatarUrl())->toBeNull();

    test()->actingAs($riley)->delete(route('admin.users.photo.destroy', $riley))->assertNotFound();
});

it('shows a photo to anyone signed in, and keeps it from everyone else', function () {
    $riley = userWithRole('Sourcing');
    putUpPhoto($riley);

    $response = test()->actingAs(userWithRole('Data Entry'))->get($riley->refresh()->avatarUrl())->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    auth()->logout();
    test()->get($riley->avatarUrl())->assertRedirect(route('login'));

    test()->actingAs($riley)->get(route('admin.users.photo', userWithRole('Sourcing')))->assertNotFound();
});

it('takes a photo alone, and not too big', function (Closure $photo, string $message) {
    $riley = userWithRole('Sourcing');

    test()->actingAs($riley)->from(route('admin.settings.edit'))
        ->post(route('admin.users.photo.update', $riley), ['photo' => $photo(), 'return_to' => 'settings'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHasErrorsIn('photo', ['photo' => $message]);

    expect($riley->refresh()->avatar_path)->toBeNull();
})->with([
    'nothing' => [fn () => null, 'Choose a photo.'],
    'a PDF' => [fn () => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'), 'Choose a photo — JPG, PNG, WebP or GIF.'],
    'an SVG' => [fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'), 'Choose a photo — JPG, PNG, WebP or GIF.'],
    'too big' => [fn () => UploadedFile::fake()->image('huge.jpg')->size(6000), 'Keep the photo under 5 MB.'],
]);

it('says on the page why a photo didn\'t go in', function () {
    $riley = userWithRole('Sourcing');

    test()->actingAs($riley)->from(route('admin.settings.edit'))->followingRedirects()
        ->post(route('admin.users.photo.update', $riley), ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'), 'return_to' => 'settings'])
        ->assertSee('Choose a photo — JPG, PNG, WebP or GIF.');
});

it('leaves someone else\'s photo to Admin and HR Manager — and an Admin\'s to an Admin', function () {
    $riley = userWithRole('Sourcing');
    $admin = userWithRole('Admin');
    $hr = User::factory()->create()->assignRole('HR Manager');

    putUpPhoto(userWithRole('Senior Operations'), $riley)->assertForbidden();
    putUpPhoto($hr, $admin)->assertForbidden();
    expect($riley->refresh()->avatar_path)->toBeNull();

    // From their page, and back to it.
    test()->actingAs($hr)->post(route('admin.users.photo.update', $riley), ['photo' => UploadedFile::fake()->image('riley.jpg')])
        ->assertRedirect(route('admin.users.show', $riley))
        ->assertSessionHas('status', 'Photo updated.');

    test()->actingAs($hr)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('class="settings-avatar user-profile-avatar has-photo"', false)
        ->assertSee('action="'.route('admin.users.photo.update', $riley).'"', false)
        ->assertSee('Remove photo');

    // An Admin's page: theirs to see, not to change.
    test()->actingAs($hr)->get(route('admin.users.show', $admin))->assertOk()
        ->assertDontSee('action="'.route('admin.users.photo.update', $admin).'"', false);

    test()->actingAs(userWithRole('Senior Operations'))->delete(route('admin.users.photo.destroy', $riley))->assertForbidden();
    test()->actingAs($admin)->delete(route('admin.users.photo.destroy', $riley))->assertRedirect(route('admin.users.show', $riley));
    expect($riley->refresh()->avatar_path)->toBeNull();

    putUpPhoto($admin, userWithRole('Admin'))->assertRedirect();
});

it('shows people\'s photos where they appear — on the parts they hold, in a comment\'s card', function () {
    $riley = userWithRole('Sourcing');
    putUpPhoto($riley);
    $riley->refresh();

    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->comments()->create(['user_id' => $riley->id, 'body' => 'Quotes are in']);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('class="assignee-avatar has-photo"><img src="'.$riley->avatarUrl().'"', false)
        ->assertSee('data-photo="'.$riley->avatarUrl().'"', false);
});

it('takes their photo with them when their account is deleted', function () {
    $riley = userWithRole('Sourcing');
    putUpPhoto($riley);
    $path = $riley->refresh()->avatar_path;

    $riley->delete();

    Storage::disk(User::AVATAR_DISK)->assertMissing($path);
});
