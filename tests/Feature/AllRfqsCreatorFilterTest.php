<?php

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

/**
 * RFQs made by two people in Business Development (one since deactivated),
 * an Admin, and someone who's since moved to Senior Operations.
 *
 * @return array{bailey: User, casey: User, admin: User, moved: User}
 */
function madeByWhom(): array
{
    $bailey = tap(userWithRole('Business Development'))->update(['name' => 'Bailey Nguyen']);
    $casey = tap(userWithRole('Business Development'))->forceFill(['name' => 'Casey Lim', 'deactivated_at' => now()])->save();
    $casey = User::query()->where('name', 'Casey Lim')->sole();
    $admin = tap(userWithRole('Admin'))->update(['name' => 'Ada Admin']);
    $moved = tap(userWithRole('Senior Operations'))->update(['name' => 'Morgan Moved']);

    Rfq::factory()->create(['rfq_number' => 'RFQ6101', 'created_by' => $bailey->id]);
    Rfq::factory()->create(['rfq_number' => 'RFQ6102', 'created_by' => $bailey->id, 'status' => 'Completed']);
    Rfq::factory()->create(['rfq_number' => 'RFQ6103', 'created_by' => $casey->id]);
    Rfq::factory()->create(['rfq_number' => 'RFQ6104', 'created_by' => $admin->id]);
    Rfq::factory()->create(['rfq_number' => 'RFQ6105', 'created_by' => $moved->id]);

    return compact('bailey', 'casey', 'admin', 'moved');
}

/**
 * The RFQ numbers All RFQs lists for $query, as $viewer sees it.
 *
 * @param  array<string, mixed>  $query
 * @return array<int, string>
 */
function allRfqsBy(array $query, ?User $viewer = null): array
{
    return test()->actingAs($viewer ?? userWithRole('Admin'))->get(route('admin.rfqs.index', $query))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->sort()->values()->all();
}

it('offers anyone in Business Development or Admin, then each by name, under their role', function () {
    ['bailey' => $bailey, 'casey' => $casey, 'admin' => $admin, 'moved' => $moved] = madeByWhom();

    $filters = Str::betweenFirst(test()->actingAs($admin)->get(route('admin.rfqs.index'))->assertOk()->getContent(), 'id="rfqListFilterForm"', '</form>');
    preg_match_all('/<optgroup label="([^"]+)">|<option value="([^"]*)"[^>]*>Created by: ([^<]+)</', $filters, $found, PREG_SET_ORDER);

    expect(collect($found)->map(fn (array $match) => $match[1] !== '' ? "[{$match[1]}]" : "{$match[2]} {$match[3]}")->all())->toBe([
        ' Anyone',
        '[Business Development]',
        'business-development Anyone in Business Development',
        "{$bailey->id} Bailey Nguyen",
        "{$casey->id} Casey Lim (deactivated)",
        '[Admin]',
        'admin Any Admin',
        "{$admin->id} Ada Admin",
        '[Other]',
        "{$moved->id} Morgan Moved",
    ]);
});

it('narrows All RFQs to who made them — one person, or anyone in a role — whatever their status', function () {
    ['bailey' => $bailey, 'admin' => $admin, 'moved' => $moved] = madeByWhom();

    expect(allRfqsBy([]))->toBe(['RFQ6101', 'RFQ6102', 'RFQ6103', 'RFQ6104', 'RFQ6105'])
        ->and(allRfqsBy(['creator' => $bailey->id]))->toBe(['RFQ6101', 'RFQ6102'])
        ->and(allRfqsBy(['creator' => 'business-development']))->toBe(['RFQ6101', 'RFQ6102', 'RFQ6103'])
        ->and(allRfqsBy(['creator' => 'admin']))->toBe(['RFQ6104'])
        ->and(allRfqsBy(['creator' => $moved->id]))->toBe(['RFQ6105'])
        // With the Status filter.
        ->and(allRfqsBy(['creator' => $bailey->id, 'state' => 'closed']))->toBe(['RFQ6102'])
        // Anything not offered is no filter at all.
        ->and(allRfqsBy(['creator' => 'nobody']))->toHaveCount(5)
        ->and(allRfqsBy(['creator' => '999999']))->toHaveCount(5);

    $page = test()->actingAs($admin)->get(route('admin.rfqs.index', ['creator' => $bailey->id]))->assertOk()->getContent();

    expect($page)->toContain('<option value="'.$bailey->id.'" selected>Created by: Bailey Nguyen</option>')
        ->toContain('data-all-rfqs-creator="'.$bailey->id.'"')
        ->toContain('Showing RFQs created by <strong>Bailey Nguyen</strong> — 2 RFQs.')
        ->and(test()->actingAs($admin)->get(route('admin.rfqs.index', ['creator' => 'business-development', 'state' => 'closed']))->getContent())
        ->toContain('Showing RFQs with status <strong>Closed</strong>, created by <strong>Anyone in Business Development</strong> — 1 RFQ.');
});

it('is on All RFQs alone', function () {
    ['bailey' => $bailey] = madeByWhom();
    $ops = userWithRole('Senior Operations');

    // Senior Operations' own Pending page ignores it, and has no such filter.
    $pending = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'creator' => $bailey->id]))->assertOk();
    expect($pending->getContent())->not->toContain('name="creator"');

    // Senior Operations' All RFQs has it.
    expect(allRfqsBy(['creator' => $bailey->id], $ops))->toBe(['RFQ6101', 'RFQ6102']);
});
