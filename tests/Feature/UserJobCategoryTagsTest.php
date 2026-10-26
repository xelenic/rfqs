<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }
    Role::findOrCreate('Admin')->givePermissionTo(['users.view', 'users.create', 'users.edit', 'users.delete']);
    Notification::fake();
});

/**
 * Electrical Works, Fire Safety and Plumbing & Sanitary, by name.
 *
 * @return array<string, JobCategory>
 */
function tagCategories(): array
{
    return collect(['Electrical Works', 'Fire Safety', 'Plumbing & Sanitary'])
        ->mapWithKeys(fn (string $name) => [$name => JobCategory::query()->create(['name' => $name])])
        ->all();
}

it('asks, adding someone, for the job categories they take on — a field to type them in, for Sourcing and Data Entry', function () {
    ['Electrical Works' => $electrical, 'Plumbing & Sanitary' => $plumbing] = tagCategories();

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.users.index'))->assertOk()->getContent();
    $tags = Str::betweenFirst($html, 'js-job-category-tags', '</div>');
    $form = Str::betweenFirst($html, 'id="createUserModal"', '</form>');
    $options = json_decode(Str::before(Str::after($form, 'js-category-tag-options">'), '</script>'), true);

    expect($tags)->toContain('d-none')
        ->toContain('data-tag-roles="Sourcing|Data Entry"')
        ->and($form)->toContain('class="category-tag-input js-category-tag-input" id="create-category-search"')
        ->toContain('placeholder="Type a category…"')
        ->not->toContain('name="job_categories[]"')
        ->and($options)->toContain(['id' => $electrical->id, 'name' => 'Electrical Works'])
        ->toContain(['id' => $plumbing->id, 'name' => 'Plumbing & Sanitary']);
});

it('keeps the tags picked when the new person\'s details come back to fix', function () {
    ['Electrical Works' => $electrical, 'Fire Safety' => $fire] = tagCategories();
    $admin = userWithRole('Admin');

    $html = test()->actingAs($admin)->from(route('admin.users.index'))->followingRedirects()->post(route('admin.users.store'), [
        'name' => 'Riley Chen', 'email' => 'not-an-email', 'roles' => ['Sourcing'], 'job_categories' => [$fire->id, $electrical->id],
    ])->assertOk()->getContent();

    $form = Str::betweenFirst($html, 'id="createUserModal"', '</form>');
    $tags = Str::betweenFirst($form, 'js-category-tag-chips">', '<input type="text"');

    expect(Str::betweenFirst($form, 'js-job-category-tags', '>'))->not->toContain('d-none')
        ->and($tags)->toContain('data-category-id="'.$fire->id.'"')
        ->toContain('<input type="hidden" name="job_categories[]" value="'.$electrical->id.'">')
        ->and(strpos($tags, 'Fire Safety'))->toBeLessThan(strpos($tags, 'Electrical Works'));
});

it('tags someone in Sourcing or Data Entry with the categories picked, and shows them', function (string $role) {
    ['Electrical Works' => $electrical, 'Fire Safety' => $fire] = tagCategories();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Riley Chen', 'email' => 'riley@example.test', 'roles' => [$role], 'job_categories' => [$electrical->id, $fire->id],
    ])->assertSessionHasNoErrors();

    $riley = User::query()->where('email', 'riley@example.test')->sole();
    expect($riley->jobCategories->pluck('name')->all())->toBe(['Electrical Works', 'Fire Safety']);

    test()->actingAs($admin)->get(route('admin.users.index'))->assertOk()
        ->assertSee('data-job-categories="'.$riley->id.'"', false)
        ->assertSee('data-categories="'.$electrical->id.','.$fire->id.'"', false);
    expect(Str::betweenFirst(test()->actingAs($admin)->get(route('admin.users.show', $riley))->getContent(), 'data-job-categories="'.$riley->id.'"', '</dd>'))
        ->toContain('Electrical Works')->toContain('Fire Safety');
})->with(['Sourcing', 'Data Entry']);

it('tags nobody else — and takes the tags off with the role', function () {
    ['Electrical Works' => $electrical, 'Fire Safety' => $fire] = tagCategories();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Olivia Park', 'email' => 'olivia@example.test', 'roles' => ['Senior Operations'], 'job_categories' => [$electrical->id],
    ])->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'olivia@example.test')->sole()->jobCategories)->toBeEmpty();

    $riley = userWithRole('Sourcing');
    $riley->jobCategories()->sync([$electrical->id]);

    test()->actingAs($admin)->put(route('admin.users.update', $riley), [
        'user_id' => $riley->id, 'name' => $riley->name, 'email' => $riley->email, 'roles' => ['Sourcing'], 'job_categories' => [$fire->id],
    ])->assertSessionHasNoErrors();
    expect($riley->fresh()->jobCategories->pluck('name')->all())->toBe(['Fire Safety']);

    test()->actingAs($admin)->put(route('admin.users.update', $riley), [
        'user_id' => $riley->id, 'name' => $riley->name, 'email' => $riley->email, 'roles' => ['Senior Operations'], 'job_categories' => [$fire->id],
    ])->assertSessionHasNoErrors();
    expect($riley->fresh()->jobCategories)->toBeEmpty();
});

it('refuses a category that isn\'t on the list, and drops a tag with its category', function () {
    ['Electrical Works' => $electrical] = tagCategories();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.store'), [
        'name' => 'Riley Chen', 'email' => 'riley@example.test', 'roles' => ['Sourcing'], 'job_categories' => [99999],
    ])->assertSessionHasErrorsIn('create', ['job_categories.0' => 'Pick from the job categories listed.']);
    expect(User::query()->where('email', 'riley@example.test')->exists())->toBeFalse();

    $riley = userWithRole('Sourcing');
    $riley->jobCategories()->sync([$electrical->id]);
    test()->actingAs($admin)->delete(route('admin.categories.destroy', $electrical))->assertRedirect();

    expect($riley->fresh()->jobCategories)->toBeEmpty();
});

it('keeps a job category\'s name from breaking out of the tag field\'s list', function () {
    JobCategory::query()->create(['name' => 'Wiring </script><script>alert(1)</script>']);

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.users.index'))->assertOk()->getContent();
    $form = Str::betweenFirst($html, 'id="createUserModal"', '</form>');

    expect($html)->not->toContain('<script>alert(1)')
        ->and(json_decode(Str::before(Str::after($form, 'js-category-tag-options">'), '</script>'), true))
        ->toBe([['id' => JobCategory::query()->sole()->id, 'name' => 'Wiring </script><script>alert(1)</script>']]);
});
