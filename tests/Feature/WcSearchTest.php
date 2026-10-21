<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * The suggestions for $term, as $viewer (Business Development by default) gets them.
 */
function wcSuggestions(string $term, ?User $viewer = null): TestResponse
{
    return test()->actingAs($viewer ?? userWithRole('Business Development'))
        ->getJson(route('admin.rfqs.suggest', ['q' => $term]))
        ->assertOk();
}

// ---- the box ---------------------------------------------------------------

it('puts the WC number search on every dashboard', function (string $role) {
    test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="wcSearchForm"', false)
        ->assertSee('data-suggest-url="'.route('admin.rfqs.suggest').'"', false)
        ->assertSee('role="combobox"', false)
        ->assertSee('js/wc-search.js', false);
})->with(['Admin', 'Business Development', 'Sourcing', 'Senior Operations', 'Data Entry']);

it('leaves it off for those who can\'t open RFQs, and turns their requests down', function () {
    $hr = User::factory()->create()->assignRole('HR Executive');

    test()->actingAs($hr)->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee('id="wcSearchForm"', false);
    test()->actingAs($hr)->getJson(route('admin.rfqs.suggest', ['q' => 'WC']))->assertForbidden();
});

// ---- the suggestions -------------------------------------------------------

it('suggests the RFQs whose WC number has what\'s typed — spaces and case aside — those starting with it first', function () {
    Rfq::factory()->create(['rfq_number' => 'RFQ6001', 'wc_number' => 'AB-WC20', 'created_at' => now()]);
    Rfq::factory()->create(['rfq_number' => 'RFQ6002', 'wc_number' => ' wc2054', 'created_at' => now()->subDays(2), 'subject' => 'Fire pump']);
    Rfq::factory()->create(['rfq_number' => 'RFQ6003', 'wc_number' => 'WC2099', 'created_at' => now()->subDay(), 'priority_level' => 'Urgent']);
    Rfq::factory()->create(['rfq_number' => 'RFQ6004', 'wc_number' => 'WC3000']);

    $response = wcSuggestions(' wc20 ')
        ->assertJsonPath('term', 'WC20')
        ->assertJsonPath('total', 3)
        ->assertJsonPath('all', route('admin.rfqs.index', ['search' => 'WC20']));

    // Starting with it, newest first; then the one with it further in.
    expect(collect($response->json('results'))->pluck('rfq')->all())->toBe(['RFQ6003', 'RFQ6002', 'RFQ6001'])
        ->and($response->json('results.0'))->toMatchArray([
            'wc' => 'WC2099',
            'priority' => 'P2 – Urgent',
            'priorityClass' => 'badge-priority priority-chip-urgent',
            'status' => 'Pending',
            'url' => route('admin.rfqs.show', Rfq::where('rfq_number', 'RFQ6003')->sole()),
        ])
        ->and($response->json('results.1'))->toMatchArray(['wc' => ' wc2054', 'subject' => 'Fire pump']);
});

it('suggests at most a handful, saying how many there are in all', function () {
    foreach (range(1, 11) as $number) {
        Rfq::factory()->create(['wc_number' => 'WC50'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }

    $response = wcSuggestions('WC50')->assertJsonPath('total', 11);

    expect($response->json('results'))->toHaveCount(8);
});

it('suggests nothing for nothing, and takes a % or _ as it is', function () {
    Rfq::factory()->create(['wc_number' => 'WC1234']);
    Rfq::factory()->create(['wc_number' => 'WC_50%']);

    wcSuggestions('   ')->assertJsonPath('total', 0)->assertJsonPath('results', []);
    wcSuggestions('%')->assertJsonPath('total', 1)->assertJsonPath('results.0.wc', 'WC_50%');
    wcSuggestions('C_')->assertJsonPath('total', 1);
});

it('is a route of its own, not an RFQ called "suggest"', function () {
    expect(route('admin.rfqs.suggest'))->toEndWith('/admin/rfqs/suggest');

    wcSuggestions('WC')->assertJsonStructure(['term', 'total', 'results', 'all']);
});
