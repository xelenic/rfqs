<?php

use App\Models\Rfq;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

it('shows a comment\'s and a reply\'s full date and time — on the timeline and in the popup — with "ago" on hover', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    test()->travelTo(CarbonImmutable::parse('2026-10-16 19:27', Setting::DEFAULT_TIMEZONE));
    $comment = $rfq->comments()->create(['user_id' => $riley->id, 'body' => 'Prices attached']);
    test()->travelTo(CarbonImmutable::parse('2026-10-16 20:05', Setting::DEFAULT_TIMEZONE));
    $rfq->comments()->create(['user_id' => $riley->id, 'body' => 'And the quote', 'parent_id' => $comment->id]);
    test()->travelTo(CarbonImmutable::parse('2026-10-17 10:00', Setting::DEFAULT_TIMEZONE));

    // The RFQ's page: its timeline.
    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('<span class="text-muted-soft rfq-comment-time">Oct 16, 2026 7:27 PM</span>', false)
        ->assertSee('Oct 16, 2026 8:05 PM')
        ->assertDontSee('hours ago');

    // Sourcing's list: the quick-detail popup's thread.
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<span class="text-muted-soft rfq-comment-time">Oct 16, 2026 7:27 PM</span>', false)
        ->assertSee('<span class="text-muted-soft rfq-comment-time">Oct 16, 2026 8:05 PM</span>', false)
        ->assertDontSee('hours ago');
});

it('shows Sourcing only Sourcing\'s time on the RFQ\'s page — not the other roles\', nor the total', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $timeCard = fn (string $html) => Str::betweenFirst($html, '<span>Time spent</span>', '</div>
</div>');

    $sourcing = test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();
    expect($sourcing)->toContain('<span>Time spent</span>')
        ->and($timeCard($sourcing))->toContain('Sourcing')
        ->not->toContain('Data Entry')
        ->not->toContain('GM Assistant')
        ->and($sourcing)->not->toContain('id="rfq-time-spent-total"');

    // Everyone else sees every role, and the total.
    $admin = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();
    expect($timeCard($admin))->toContain('Data Entry')->toContain('GM Assistant')
        ->and($admin)->toContain('id="rfq-time-spent-total"');
});

it('shows when each of today\'s ongoing tasks was last updated, as a date and time', function () {
    $ops = userWithRole('Senior Operations');
    test()->travelTo(CarbonImmutable::parse('2026-10-19 09:15', Setting::DEFAULT_TIMEZONE));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1679']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    test()->travelTo(CarbonImmutable::parse('2026-10-19 16:20', Setting::DEFAULT_TIMEZONE));

    $ongoing = fn () => Str::after(test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->assertOk()->getContent(), "Today's ongoing tasks");

    expect($ongoing())
        ->toContain('Last Updated</th>')
        ->not->toContain('>Started</th>')
        ->toContain('Oct 19, 2026 9:15 AM')
        ->not->toContain('hours ago');

    // Held and resumed: it was updated then.
    $rfq->refresh()->changePartStatus(1, Rfq::ON_HOLD, $ops, 'Waiting');
    test()->travelTo(CarbonImmutable::parse('2026-10-19 16:45', Setting::DEFAULT_TIMEZONE));
    $rfq->refresh()->changePartStatus(1, 'Pending', $ops);

    expect(Str::betweenFirst($ongoing(), 'RFQ1679-P1 of P2', '</tr>'))->toContain('Oct 19, 2026 4:45 PM');
});
