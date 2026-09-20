<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A user holding $role, which can view and edit RFQs (as every workflow role
 * can). Creates the role and permissions on first use.
 */
function userWithRole(string $role): User
{
    Permission::findOrCreate('rfqs.view');
    Permission::findOrCreate('rfqs.edit');

    Role::findOrCreate($role)->givePermissionTo(['rfqs.view', 'rfqs.edit']);

    return User::factory()->create()->assignRole($role);
}

/**
 * Splits an RFQ into $parts, assigning each to the given Sourcing members
 * (part number => user) straight through the model.
 *
 * @param  array<int, User>  $holders
 */
function splitAmong(Rfq $rfq, array $holders): Rfq
{
    $rfq->planSplit(count($holders));
    $rfq->assignSourcingParts(collect($holders)->map(fn ($user) => $user->id)->all());

    return $rfq->refresh();
}

/**
 * Senior Operations' Pending page (or Admin's view of it, with $role), split
 * at the Assigned tab: [what comes before it — the filters and the Unassigned
 * tab, the Assigned tab onwards, the whole page]. $query is added to the URL.
 *
 * @param  array<string, mixed>  $query
 * @return array{0: string, 1: string, 2: string}
 */
function operationsTabs(array $query = [], ?string $role = null): array
{
    $user = $role ? userWithRole('Admin') : userWithRole('Senior Operations');

    $html = test()->actingAs($user)
        ->get(route('admin.rfqs.index', array_filter(['status' => 'Pending', 'role' => $role]) + $query))
        ->assertOk()
        ->getContent();

    $assignedAt = strpos($html, 'id="rfq-ops-assigned"');

    return [substr($html, 0, $assignedAt), substr($html, $assignedAt), $html];
}
