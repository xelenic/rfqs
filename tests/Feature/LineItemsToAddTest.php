<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

function assignWithLineItems(User $riley, Rfq $rfq, array $payload): TestResponse
{
    return test()->actingAs($riley)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Prices are in'] + $payload);
}

it('asks Sourcing how many line items Data Entry has to add, as they assign it', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(), [1 => $riley]);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Line items to be added')
        ->assertSee('name="line_items"', false);
});

it('keeps the number with the part, says it on the thread, and shows it to Data Entry and Sourcing', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8801']), [1 => $riley]);

    assignWithLineItems($riley, $rfq, ['line_items' => 24])->assertSessionHas('status');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->line_items_to_add)->toBe(24)
        ->and($rfq->comments()->where('action', 'sourcing_completed')->sole()->actionContext())->toBe('24 line items to add');

    // Data Entry's Ready list says so, and their Send to Finalize knows it.
    $html = test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    expect(Str::betweenFirst($html, '<td class="text-nowrap">RFQ8801</td>', '</tr>'))->toContain('24 line items to add');

    startDataEntryOn($rfq, 1, $morgan);
    $html = test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    expect($html)->toMatch('/data-kind="data_entry"[^>]*data-line-items="24"/');

    // And Sourcing's With Data Entry tab.
    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'with-data-entry']))->getContent();
    expect(Str::after($html, 'id="rfq-sourcing-with-de"'))->toContain('24 line items to add');
});

it('won\'t assign it without a sensible number', function (array $payload) {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    assignWithLineItems($riley, $rfq, $payload)
        ->assertSessionHas('error', 'Add how many line items Data Entry has to add — a whole number, 1 or more.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull();
})->with([
    'none' => [[]],
    'zero' => [['line_items' => 0]],
    'not a number' => [['line_items' => 'lots']],
    'a fraction' => [['line_items' => '2.5']],
    'too many' => [['line_items' => 100001]],
]);

it('offers the number given last time when the part comes back to assign again', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    assignWithLineItems($riley, $rfq, ['line_items' => 15]);
    $rfq->refresh()->returnSourcingPart(1, 'Two items are missing', $morgan);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('data-line-items="15"', false);

    assignWithLineItems($riley, $rfq, ['line_items' => 17]);
    expect($rfq->refresh()->assigneeForPart(1)->pivot->line_items_to_add)->toBe(17);
});
