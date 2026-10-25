<?php

use App\Models\JobCategory;
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

function categoryAdmin(): User
{
    return tap(userWithRole('Admin'))->update(['name' => 'Ada Admin']);
}

it('is for Admin, Senior Operations and GM Assistant, in their sidebar', function () {
    foreach ([categoryAdmin(), userWithRole('Senior Operations'), userWithRole('GM Assistant')] as $person) {
        test()->actingAs($person)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="'.route('admin.categories.index').'"', false)
            ->assertSee('Job Categories');
        test()->actingAs($person)->get(route('admin.categories.index'))->assertOk();
    }

    foreach (['Sourcing', 'Business Development', 'Data Entry', 'General Manager', 'Head of Business Development'] as $role) {
        $person = userWithRole($role);
        test()->actingAs($person)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.categories.index'));
        test()->actingAs($person)->get(route('admin.categories.index'))->assertForbidden();
        test()->actingAs($person)->post(route('admin.categories.store'), ['name' => 'Nope'])->assertForbidden();
    }
});

it('lists each category with its description, its RFQs — all and open — and who added it', function () {
    $admin = categoryAdmin();
    $electrical = JobCategory::query()->create(['name' => 'Electrical Works', 'description' => 'Wiring and lighting', 'created_by' => $admin->id]);
    JobCategory::query()->create(['name' => 'Plumbing & Sanitary']);
    Rfq::factory()->count(2)->create(['category' => 'Electrical Works', 'status' => 'Pending']);
    Rfq::factory()->create(['category' => 'Electrical Works', 'status' => 'Completed', 'stage' => 'closed']);

    $html = test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('<title>Job Categories', false)
        ->assertSee('2 categories in the Assign Sourcing wizard')
        ->getContent();
    $row = Str::betweenFirst($html, 'data-category="'.$electrical->id.'"', '</tr>');

    expect($row)->toContain('bi-lightning-charge')
        ->toContain('Electrical Works')
        ->toContain('Wiring and lighting')
        ->toContain('<span class="fw-semibold">3</span>')
        ->toContain('2 open')
        ->toContain('by Ada Admin')
        ->and($html)->toContain('Set up with the system');

    // Searched by name or description.
    test()->actingAs($admin)->get(route('admin.categories.index', ['search' => 'wiring']))->assertOk()
        ->assertSee('Electrical Works')
        ->assertDontSee('Plumbing &amp; Sanitary', false);
});

it('adds a category — not one there already, whatever its case — and the wizard offers it', function () {
    $admin = categoryAdmin();
    JobCategory::query()->create(['name' => 'Electrical Works']);

    test()->actingAs($admin)->post(route('admin.categories.store'), ['name' => '  Solar Panels ', 'description' => 'Panels and inverters'])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('status', 'Added "Solar Panels" — it\'s in the Assign Sourcing wizard\'s list now.');

    expect(JobCategory::query()->where('name', 'Solar Panels')->sole())
        ->description->toBe('Panels and inverters')
        ->created_by->toBe($admin->id);

    test()->actingAs($admin)->from(route('admin.categories.index'))->post(route('admin.categories.store'), ['name' => 'electrical works'])
        ->assertSessionHasErrorsIn('createCategory', ['name' => 'There\'s already a category called that.']);
    test()->actingAs($admin)->from(route('admin.categories.index'))->post(route('admin.categories.store'), ['name' => ''])
        ->assertRedirect(route('admin.categories.index'));

    // Back in the popup, saying what's wrong.
    test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('Give the category a name.')
        ->assertSee('getElementById(\'createCategoryModal\')).show()', false);

    // Senior Operations' wizard lists it.
    $rfq = Rfq::factory()->create(['status' => 'Pending']);
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Solar Panels');
    expect($rfq->exists)->toBeTrue();
});

it('renames a category — on the RFQs that have it too — and edits its description', function () {
    $admin = categoryAdmin();
    $category = JobCategory::query()->create(['name' => 'Electical Works', 'description' => 'Old']);
    JobCategory::query()->create(['name' => 'Plumbing']);
    $rfqs = Rfq::factory()->count(2)->create(['category' => 'Electical Works']);
    $other = Rfq::factory()->create(['category' => 'Plumbing']);

    test()->actingAs($admin)->put(route('admin.categories.update', $category), ['name' => 'Electrical Works', 'description' => 'Wiring and lighting'])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('status', 'Renamed "Electical Works" to "Electrical Works" — and on its 2 RFQs.');

    expect($category->refresh())->name->toBe('Electrical Works')->description->toBe('Wiring and lighting')
        ->and($rfqs->map(fn (Rfq $rfq) => $rfq->refresh()->category)->unique()->all())->toBe(['Electrical Works'])
        ->and($other->refresh()->category)->toBe('Plumbing');

    // Not another's name; the description alone is a plain save.
    test()->actingAs($admin)->from(route('admin.categories.index'))->put(route('admin.categories.update', $category), ['name' => 'PLUMBING', 'category_id' => $category->id])
        ->assertRedirect(route('admin.categories.index'));
    test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('There&#039;s already a category called that.', false)
        ->assertSee('action="'.route('admin.categories.update', $category).'"', false)
        ->assertSee('getElementById(\'editCategoryModal\')).show()', false);

    test()->actingAs($admin)->put(route('admin.categories.update', $category), ['name' => 'Electrical Works', 'description' => ''])
        ->assertSessionHas('status', 'Saved "Electrical Works".');
    expect($category->refresh()->description)->toBeNull();
});

it('deletes a category — its RFQs keep it, and it can be put back from the list', function () {
    $admin = categoryAdmin();
    $category = JobCategory::query()->create(['name' => 'Pest Control']);
    $rfq = Rfq::factory()->create(['category' => 'Pest Control']);

    test()->actingAs($admin)->delete(route('admin.categories.destroy', $category))
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('status', 'Deleted "Pest Control" — its 1 RFQ keeps it as their category.');

    expect(JobCategory::query()->count())->toBe(0)
        ->and($rfq->refresh()->category)->toBe('Pest Control');

    $html = test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()->getContent();
    expect(Str::betweenFirst($html, 'data-unlisted-category="Pest Control"', '</div>'))->toContain('1 RFQ')->toContain('Put back on the list');

    test()->actingAs($admin)->post(route('admin.categories.store'), ['name' => 'Pest Control'])->assertSessionHasNoErrors();
    expect(test()->actingAs($admin)->get(route('admin.categories.index'))->getContent())->not->toContain('data-unlisted-category');
});

it('lets Senior Operations and GM Assistant add, rename and delete categories', function () {
    $operations = tap(userWithRole('Senior Operations'))->update(['name' => 'Omar Ops']);
    $assistant = userWithRole('GM Assistant');
    Rfq::factory()->create(['category' => 'Solar Panels']);

    test()->actingAs($operations)->post(route('admin.categories.store'), ['name' => 'Solar Panels', 'description' => 'PV modules'])
        ->assertRedirect(route('admin.categories.index'));
    $category = JobCategory::query()->where('name', 'Solar Panels')->sole();
    expect($category->created_by)->toBe($operations->id);

    test()->actingAs($assistant)->put(route('admin.categories.update', $category), ['name' => 'Solar Energy', 'category_id' => $category->id])
        ->assertRedirect(route('admin.categories.index'));
    expect(Rfq::query()->where('category', 'Solar Energy')->count())->toBe(1);

    test()->actingAs($assistant)->delete(route('admin.categories.destroy', $category))->assertRedirect(route('admin.categories.index'));
    expect(JobCategory::query()->whereKey($category->id)->exists())->toBeFalse();

    // Its RFQ keeps it — on the list to put back.
    test()->actingAs($operations)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('data-unlisted-category="Solar Energy"', false);
});

it('moves the RFQs of a category not on the list to one that is', function () {
    $ops = userWithRole('Senior Operations');
    $electrical = JobCategory::query()->create(['name' => 'Electrical Works']);
    JobCategory::query()->create(['name' => 'Plumbing & Sanitary']);
    Rfq::factory()->count(2)->create(['category' => 'Old Wiring']);
    Rfq::factory()->create(['category' => 'Electrical Works']);

    $html = test()->actingAs($ops)->get(route('admin.categories.index'))->assertOk()
        ->assertSee('id="reassignCategoryModal"', false)
        ->getContent();
    expect(Str::betweenFirst($html, 'data-unlisted-category="Old Wiring"', '</form>'))
        ->toContain('js-reassign-category')
        ->toContain('data-from="Old Wiring"')
        ->toContain('data-rfqs="2"')
        ->and(Str::betweenFirst($html, 'id="reassign-category-to"', '</select>'))
        ->toContain('Electrical Works')->toContain('Plumbing &amp; Sanitary');

    test()->actingAs($ops)->post(route('admin.categories.reassign'), ['from' => 'Old Wiring', 'to' => $electrical->id])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('status', 'Moved the 2 RFQs with "Old Wiring" to "Electrical Works".');

    expect(Rfq::query()->where('category', 'Electrical Works')->count())->toBe(3)
        ->and(Rfq::query()->where('category', 'Old Wiring')->exists())->toBeFalse();

    test()->actingAs($ops)->get(route('admin.categories.index'))->assertOk()
        ->assertDontSee('data-unlisted-category', false);
});

it('refuses a reassign of a category on the list, one no RFQ has, or with nowhere to go — back in the popup', function (Closure $payload, string $message) {
    $admin = categoryAdmin();
    $electrical = JobCategory::query()->create(['name' => 'Electrical Works']);
    Rfq::factory()->create(['category' => 'Old Wiring']);

    test()->actingAs($admin)->from(route('admin.categories.index'))
        ->post(route('admin.categories.reassign'), $payload($electrical))
        ->assertRedirect(route('admin.categories.index'));

    test()->actingAs($admin)->get(route('admin.categories.index'))->assertOk()
        ->assertSee($message)
        ->assertSee("getElementById('reassignCategoryModal')", false);

    expect(Rfq::query()->where('category', 'Old Wiring')->count())->toBe(1);
})->with([
    'nowhere to go' => [fn (JobCategory $electrical) => ['from' => 'Old Wiring'], 'Choose the category to move them to.'],
    'a category on the list' => [fn (JobCategory $electrical) => ['from' => 'Electrical Works', 'to' => $electrical->id], 'That one\'s on the list — rename it with Edit instead.'],
    'no RFQ has it' => [fn (JobCategory $electrical) => ['from' => 'Never Used', 'to' => $electrical->id], 'No RFQ has that category any more.'],
]);

it('keeps reassigning to those who manage the categories', function () {
    $electrical = JobCategory::query()->create(['name' => 'Electrical Works']);
    Rfq::factory()->create(['category' => 'Old Wiring']);

    test()->actingAs(userWithRole('Sourcing'))->post(route('admin.categories.reassign'), ['from' => 'Old Wiring', 'to' => $electrical->id])->assertForbidden();
    expect(Rfq::query()->where('category', 'Old Wiring')->count())->toBe(1);
});
