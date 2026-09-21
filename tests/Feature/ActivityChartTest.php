<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Midday, so "earlier today" and "yesterday" mean the same at any hour.
    test()->travelTo(now()->setTime(12, 0));

    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * What the dashboard hands the chart, read back out of its container.
 *
 * @return array<string, mixed>
 */
function activityChartPayload(string $html): array
{
    preg_match('/id="rfqActivityChart" class="rfq-chart" data-chart="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '{}'), true);
}

it('draws the RFQ activity chart with Apache ECharts, loaded before the script that uses it', function (string $role) {
    $html = test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()->getContent();

    $echarts = strpos($html, 'cdn.jsdelivr.net/npm/echarts@5');
    $chart = strpos($html, 'js/rfq-chart.js');

    expect($echarts)->not->toBeFalse()
        ->and($chart)->not->toBeFalse()
        ->and($echarts)->toBeLessThan($chart);
})->with(['Admin', 'Business Development']);

it('is drawn by ECharts, not by hand', function () {
    $script = file_get_contents(public_path('js/rfq-chart.js'));

    expect($script)->toContain('echarts.init')
        ->and($script)->not->toContain('createElementNS');
});

it('hands the chart the RFQs created over the period, pending and done, by day', function () {
    Rfq::factory()->count(2)->create(['status' => 'Pending', 'created_at' => now()]);
    Rfq::factory()->create(['status' => 'Completed', 'created_at' => now()]);
    Rfq::factory()->create(['status' => 'Pending', 'created_at' => now()->subDay()]);

    $payload = activityChartPayload(
        test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard', ['range' => 7]))->getContent(),
    );

    expect($payload['labels'])->toHaveCount(7)
        ->and(array_slice($payload['labels'], -2))->toBe([now()->subDay()->format('M j'), now()->format('M j')])
        ->and(array_slice($payload['pending'], -2))->toBe([1, 2])
        ->and(array_slice($payload['completed'], -2))->toBe([0, 1])
        ->and($payload['seriesLabels'])->toBe(['completed' => 'Completed']);
});

it('calls the finished series Closed for Business Development', function () {
    Rfq::factory()->create(['status' => 'Completed', 'created_at' => now()]);

    $payload = activityChartPayload(
        test()->actingAs(userWithRole('Business Development'))->get(route('admin.dashboard'))->getContent(),
    );

    expect($payload['seriesLabels'])->toBe(['completed' => 'Closed']);
});

it('keeps the table twin beside the chart, for reading it as numbers', function () {
    Rfq::factory()->create(['status' => 'Pending', 'created_at' => now()]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="rfqActivityTable"', false)
        ->assertSee('data-target="rfqActivityTable" data-chart-target="rfqActivityChart"', false);
});
