<?php

use App\Models\PrivateMessage;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Monday 5 October, 9:00 in Colombo — the start of a working morning.
    test()->travelTo(Carbon::parse('2026-10-05 03:30:00'));

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Manager'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Travels to $time in Colombo on the same Monday ("H:i").
 */
function criticalAt(string $time): void
{
    test()->travelTo(Carbon::parse("2026-10-05 {$time}:00", 'Asia/Colombo')->utc());
}

/**
 * The Critical monitor's content, as $viewer (an Admin by default) gets it.
 */
function criticalMonitor(?User $viewer = null): TestResponse
{
    return test()->actingAs($viewer ?? userWithRole('Admin'))->get(route('admin.critical-monitor'))->assertOk();
}

/**
 * What's critical by 14:30: an Urgent Sourcing part 30 minutes past its
 * 4 hours, a Low one on track, and someone in Data Entry idle since 09:00
 * with a part waiting.
 *
 * @return array{riley: User, sam: User, idle: User}
 */
function somethingCritical(): array
{
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $idle = userWithRole('Data Entry');

    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ4001', 'priority_level' => 'Urgent', 'subject' => 'Fire pump']), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ4002', 'priority_level' => 'Low']), [1 => $sam]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ4003', 'priority_level' => 'Medium']), [1 => $sam])->completeSourcingPart(1);

    criticalAt('14:30');

    return ['riley' => $riley, 'sam' => $sam, 'idle' => $idle];
}

// ---- who has it ------------------------------------------------------------

it('puts the Critical monitor in the top bar for Admin and Senior Operations', function (string $role) {
    test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="criticalMonitorBtn"', false)
        ->assertSee('data-url="'.route('admin.critical-monitor').'"', false)
        ->assertSee('id="criticalMonitorModal"', false)
        ->assertSee('js/critical-monitor.js', false);

    criticalMonitor(userWithRole($role));
})->with(['Admin', 'Senior Operations']);

it('keeps it from everyone else', function (string $role) {
    test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee('id="criticalMonitorBtn"', false)
        ->assertDontSee('id="criticalMonitorModal"', false)
        ->assertDontSee('js/critical-monitor.js', false);

    test()->actingAs(userWithRole($role))->get(route('admin.critical-monitor'))->assertForbidden();
})->with(['Sourcing', 'Data Entry', 'Business Development', 'General Manager', 'HR Manager']);

// ---- the button ------------------------------------------------------------

it('counts what\'s critical on the button — overdue tasks and Data Entry idle — and glows while there is', function () {
    test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))
        ->assertSee('class="critical-btn" id="criticalMonitorBtn"', false)
        ->assertDontSee('critical-btn-count', false);

    Cache::flush();
    somethingCritical();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))
        ->assertSee('class="critical-btn is-alert" id="criticalMonitorBtn"', false)
        ->assertSee('<span class="critical-btn-count">2</span>', false)
        ->assertSee('title="2 critical things need attention"', false);
});

it('puts the button\'s count right whenever the monitor\'s opened', function () {
    $admin = userWithRole('Admin');
    somethingCritical();

    // Still the count it kept, from before any of it…
    Cache::put('critical-monitor.count', 0, 60);
    test()->actingAs($admin)->get(route('admin.dashboard'))->assertDontSee('critical-btn-count', false);

    // …till the monitor works it out again.
    criticalMonitor($admin)->assertSee('data-critical-count="2"', false);
    test()->actingAs($admin)->get(route('admin.dashboard'))->assertSee('<span class="critical-btn-count">2</span>', false);
});

// ---- what's in it ----------------------------------------------------------

it('is the window\'s content alone, not a page', function () {
    $html = criticalMonitor()->getContent();

    expect($html)->toContain('class="critical-monitor"')
        ->not->toContain('<html')
        ->not->toContain('id="sidebar"');
});

it('shows the overdue tasks, the share on time, and who in Data Entry is idle', function () {
    ['riley' => $riley, 'idle' => $idle] = somethingCritical();

    $response = criticalMonitor()
        ->assertSee('data-critical-count="2"', false)
        ->assertSee('data-refresh="30"', false)
        ->assertSeeInOrder(['Overdue tasks', 'RFQ4001', 'Sourcing', e($riley->name), 'Fire pump', 'Urgent', '30m over'], false)
        ->assertSee('Longest 30m over');

    expect($response->viewData('overdue')->pluck('label')->all())->toBe(['RFQ4001'])
        ->and($response->viewData('dueSoon'))->toBe(0)
        // One of the two timed tasks running is past its target.
        ->and($response->viewData('health'))->toBe(50)
        ->and($response->viewData('idle')->pluck('user.id')->all())->toBe([$idle->id]);

    // The charts' data: the gauge, the most overdue, and idle time against the alert.
    $charts = collect(preg_match_all('/data-critical-chart="([^"]+)"/', $response->getContent(), $matches) ? $matches[1] : [])
        ->map(fn (string $json) => json_decode(html_entity_decode($json, ENT_QUOTES), true));

    expect($charts[0])->toMatchArray(['type' => 'gauge', 'value' => 50])
        ->and($charts[1])->toMatchArray(['type' => 'bars', 'values' => [0.5], 'display' => ['30m over']])
        ->and($charts[2])->toMatchArray(['type' => 'bars', 'threshold' => 10, 'display' => ['4h 30m idle']]);
});

it('lists the Urgent and High RFQs still open, Urgent first and longest-waiting first, with where each is', function () {
    Rfq::factory()->create(['rfq_number' => 'RFQ4101', 'priority_level' => 'High', 'created_at' => now()->subDays(3)]);
    Rfq::factory()->create(['rfq_number' => 'RFQ4102', 'priority_level' => 'Urgent', 'created_at' => now()->subDay()]);
    Rfq::factory()->create(['rfq_number' => 'RFQ4103', 'priority_level' => 'Urgent', 'created_at' => now()->subDays(2), 'stage' => 'gm_review']);
    Rfq::factory()->create(['rfq_number' => 'RFQ4104', 'priority_level' => 'Low']);
    Rfq::factory()->create(['rfq_number' => 'RFQ4105', 'priority_level' => 'Urgent', 'status' => 'Completed', 'stage' => 'closed']);

    $response = criticalMonitor()
        ->assertSeeInOrder(['RFQ4103', 'General Manager', 'RFQ4102', 'Awaiting assignment', 'RFQ4101'])
        ->assertDontSee('RFQ4104')
        ->assertDontSee('RFQ4105')
        ->assertSee('2 urgent · 1 high');

    expect($response->viewData('urgentCounts'))->toBe(['Urgent' => 2, 'High' => 1]);
});

it('counts what\'s been sent back and is waiting, by whose Returns page it\'s on', function () {
    Rfq::factory()->create(['reject_target_stage' => 'business_development']);

    $response = criticalMonitor();

    expect($response->viewData('returns')->firstWhere('label', 'Business Development')['count'])->toBe(1);
    $response->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns', 'role' => 'business-development'])).'"', false);
});

it('shows the viewer\'s own unread messages, and nobody else\'s', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => $admin->id, 'body' => 'The pump quote is late']);
    PrivateMessage::factory()->create(['sender_id' => $riley->id, 'recipient_id' => userWithRole('Admin')->id, 'body' => 'For someone else']);

    criticalMonitor($admin)
        ->assertSee(e($riley->name).': The pump quote is late', false)
        ->assertDontSee('For someone else')
        ->assertSee('0 alerts · 1 message');
});

it('says so when nothing is critical', function () {
    criticalMonitor()
        ->assertSee('data-critical-count="0"', false)
        ->assertSee('Nothing is past its target.')
        ->assertSee('No Urgent or High RFQ is open.')
        ->assertSee('No unread alerts.')
        ->assertSee('No unread messages.');
});
