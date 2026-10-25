<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
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

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo — 08:30–17:00,
 * lunch 12:30–13:30.
 */
function gmsClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number, High priority — an hour for GM Assistant — its one part (or
 * $parts parts) through to GM Assistant at 09:00.
 */
function readyForGmAssistant(string $number = 'RFQ9401', int $parts = 1): Rfq
{
    gmsClock('08:40');
    $holders = collect(range(1, $parts))->mapWithKeys(fn (int $part) => [$part => userWithRole('Sourcing')])->all();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => 'High', 'subject' => 'Valves']), $holders);

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $dataEntry = userWithRole('Data Entry');
        startDataEntryOn($rfq, $part, $dataEntry);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry, 'Entered', 2);
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
    }

    gmsClock('09:00');

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->approveHeadOfBdPart($part, userWithRole('Head of Business Development'));
    }

    return $rfq->refresh();
}

/**
 * $user does $action ('start' | 'pause' | 'resume') on $part of $rfq — a
 * pause saying why, and the document it's waiting on.
 *
 * @param  array<string, mixed>  $payload
 */
function gmAssistantDoes(User $user, string $action, Rfq $rfq, int $part = 1, array $payload = []): TestResponse
{
    $pauseDetails = $action === 'pause' ? ['pause_reason' => 'Waiting for the GM\'s letterhead', 'pause_reference' => 'MEMO-12'] : [];

    return test()->actingAs($user)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route("admin.rfqs.{$action}-gm-assistant", $rfq), ['part' => $part] + $payload + $pauseDetails);
}

/**
 * GM Assistant's list, and $number's row on it.
 *
 * @return array{0: string, 1: string}
 */
function gmAssistantRow(User $viewer, string $number = 'RFQ9401'): array
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    return [$html, Str::betweenFirst($html, '<td class="text-nowrap">'.$number.'</td>', '</tr>')];
}

it('offers Start on a part that\'s reached GM Assistant — not Submit — its whole target still to run', function () {
    readyForGmAssistant();

    gmsClock('09:30');
    [, $row] = gmAssistantRow(userWithRole('GM Assistant'));

    expect($row)->toContain('<i class="bi bi-play-fill"></i> Start')
        ->not->toContain('js-gm-assistant-submit')
        ->not->toContain('js-pause-data-entry')
        ->toContain('Starts when GM Assistant presses Start')
        ->toContain('1h');
});

it('times a part only from GM Assistant\'s Start, credited to whoever started it', function () {
    $rfq = readyForGmAssistant();
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    expect($rfq->gmAssistantCountdown(1))->toBeNull()
        ->and(RfqStep::query()->where('step', 'gm_assistant')->count())->toBe(0);

    gmAssistantDoes($casey, 'start', $rfq)->assertSessionHas('status', 'Started RFQ9401 — your time on it is counting.');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->gm_assistant_started_by)->toBe($casey->id)
        ->and($part->isGmAssistantRunning())->toBeTrue()
        ->and(RfqStep::query()->where('step', 'gm_assistant')->sole()->worked_by)->toBe($casey->id);

    gmsClock('09:50');
    expect($rfq->refresh()->gmAssistantCountdown(1))->toBe(['target' => 3600, 'remaining' => 2400]);

    [, $row] = gmAssistantRow($casey);
    expect($row)->toContain('Running')
        ->toContain('Started Oct 05, 2026 9:30 AM by '.e($casey->name))
        ->toContain('<i class="bi bi-pause-fill"></i> Pause')
        ->toContain('js-gm-assistant-submit');
});

it('lets GM Assistant work one part at a time', function () {
    $first = readyForGmAssistant('RFQ9401');
    $second = readyForGmAssistant('RFQ9402');
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $first);

    gmAssistantDoes($casey, 'start', $second)
        ->assertSessionHas('error', 'You\'re already on RFQ9401 — submit it, or pause it, before starting another.');
    expect($second->refresh()->assigneeForPart(1)->pivot->hasGmAssistantStarted())->toBeFalse();

    // Its Start is off, saying why.
    [, $row] = gmAssistantRow($casey, 'RFQ9402');
    expect($row)->toContain('data-busy-hint="You&#039;re already on RFQ9401')->toContain('disabled');

    // Someone else in GM Assistant still can.
    gmAssistantDoes(userWithRole('GM Assistant'), 'start', $second)->assertSessionHas('status');
});

it('pauses a part with why — its clock stops where it is, the thread is told, and another can start', function () {
    $rfq = readyForGmAssistant();
    $other = readyForGmAssistant('RFQ9402');
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $rfq);

    gmsClock('09:50');
    gmAssistantDoes($casey, 'pause', $rfq)->assertSessionHas('status', 'Paused RFQ9401 — its countdown has stopped. You can start another, and resume this one once you\'ve nothing running.');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->isGmAssistantPaused())->toBeTrue()
        ->and($part->gm_assistant_pause_reason)->toBe('Waiting for the GM\'s letterhead')
        ->and($part->gm_assistant_pause_reference)->toBe('MEMO-12')
        ->and(RfqStep::query()->where('step', 'gm_assistant')->whereNull('ended_at')->count())->toBe(0);

    $comment = RfqComment::query()->where('action', 'gm_assistant_paused')->sole();
    expect($comment->body)->toBe('Waiting for the GM\'s letterhead')
        ->and($comment->actionLabel())->toBe('Paused')
        ->and($comment->actionContext())->toBe('Ref MEMO-12');

    // Frozen at 40m left, however long it stays paused.
    gmsClock('11:00');
    expect($rfq->refresh()->gmAssistantCountdown(1))->toBeNull()
        ->and($rfq->pausedGmAssistantCountdown(1))->toBe(['target' => 3600, 'remaining' => 2400]);

    [, $row] = gmAssistantRow($casey);
    expect($row)->toContain('Paused Oct 05, 2026 9:50 AM')
        ->toContain('Waiting for the GM&#039;s letterhead')
        ->toContain('MEMO-12')
        ->toContain('<i class="bi bi-play-fill"></i> Resume')
        ->not->toContain('js-gm-assistant-submit');

    gmAssistantDoes($casey, 'start', $other)->assertSessionHas('status');
});

it('asks why it\'s paused, and only pauses a part that\'s running', function () {
    $rfq = readyForGmAssistant();
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    // Nobody's started it: it isn't theirs to pause — nor running, for Admin.
    gmAssistantDoes($casey, 'pause', $rfq)->assertForbidden();
    gmAssistantDoes(userWithRole('Admin'), 'pause', $rfq)->assertSessionHas('error', 'This part isn\'t running, so there\'s nothing to pause.');

    gmAssistantDoes($casey, 'start', $rfq);
    gmAssistantDoes($casey, 'pause', $rfq, payload: ['pause_reason' => ''])->assertSessionHas('error', 'Say why you\'re pausing it.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->isGmAssistantPaused())->toBeFalse();
});

it('resumes where it stopped — not while they\'ve another running', function () {
    $rfq = readyForGmAssistant();
    $other = readyForGmAssistant('RFQ9402');
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $rfq);
    gmsClock('09:50');
    gmAssistantDoes($casey, 'pause', $rfq);
    gmAssistantDoes($casey, 'start', $other);

    gmsClock('10:30');
    gmAssistantDoes($casey, 'resume', $rfq)->assertSessionHas('error', 'You\'re on RFQ9402 — submit it, or pause it, before resuming this one.');

    gmAssistantDoes($casey, 'pause', $other);
    gmAssistantDoes($casey, 'resume', $rfq)->assertSessionHas('status', 'Resumed RFQ9401 — its countdown carries on from where it stopped.');

    // 20 minutes before the pause, 10 since.
    gmsClock('10:40');
    expect($rfq->refresh()->assigneeForPart(1)->pivot->isGmAssistantRunning())->toBeTrue()
        ->and($rfq->gmAssistantCountdown(1))->toBe(['target' => 3600, 'remaining' => 1800]);
});

it('submits only once it\'s started, and not while it\'s paused', function () {
    $rfq = readyForGmAssistant();
    $casey = userWithRole('GM Assistant');
    $submit = fn () => test()->actingAs($casey)->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1]);

    gmsClock('09:30');
    $submit()->assertSessionHas('error', 'Start on RFQ9401 first — Submit comes after.');

    gmAssistantDoes($casey, 'start', $rfq);
    gmAssistantDoes($casey, 'pause', $rfq);
    $submit()->assertSessionHas('error', 'Resume RFQ9401 first — it\'s paused.');

    gmAssistantDoes($casey, 'resume', $rfq);
    gmsClock('10:00');
    $submit()->assertSessionHas('status', 'Submitted — every part is through, forwarded to General Manager.');

    expect($rfq->refresh()->stage)->toBe('gm_review')
        ->and(RfqStep::query()->where('step', 'gm_assistant')->whereNull('ended_at')->count())->toBe(0)
        ->and(RfqStep::query()->where('step', 'gm_assistant')->get()->every(fn (RfqStep $step) => $step->worked_by === $casey->id))->toBeTrue();
});

it('lets only whoever started it pause or resume it — or Admin for them', function () {
    $rfq = readyForGmAssistant();
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $rfq);

    gmAssistantDoes(userWithRole('GM Assistant'), 'pause', $rfq)->assertForbidden();
    gmAssistantDoes(userWithRole('Data Entry'), 'pause', $rfq)->assertForbidden();
    gmAssistantDoes(userWithRole('Data Entry'), 'start', readyForGmAssistant('RFQ9402'))->assertForbidden();

    $admin = userWithRole('Admin');
    gmAssistantDoes($admin, 'pause', $rfq)->assertSessionHas('status');
    gmAssistantDoes($admin, 'resume', $rfq)->assertSessionHas('status');
});

it('starts afresh when the General Manager sends a part back to GM Assistant', function () {
    $rfq = readyForGmAssistant();
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $rfq);
    $rfq->refresh()->recordGmAssistantPart(1, $casey);

    gmsClock('10:00');
    $rfq->refresh()->rejectPartToStage(1, 'gm_assistant', 'Wrong letterhead', userWithRole('General Manager'), 'gm_review');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->isAwaitingGmAssistant())->toBeTrue()
        ->and($part->hasGmAssistantStarted())->toBeFalse()
        ->and($rfq->partAwaitsGmAssistantStart(1))->toBeTrue();
});

it('puts Start, Pause and Submit on the RFQ\'s own page, part by part', function () {
    $rfq = readyForGmAssistant(parts: 2);
    $casey = userWithRole('GM Assistant');

    gmsClock('09:30');
    gmAssistantDoes($casey, 'start', $rfq, 1);

    $html = test()->actingAs($casey)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('id="pauseDataEntryModal"', false)
        ->getContent();

    expect($html)->toContain('data-action="'.route('admin.rfqs.pause-gm-assistant', $rfq).'"')
        ->toMatch('/js-gm-assistant-submit"[^>]*data-part="1"/')
        ->toContain('action="'.route('admin.rfqs.start-gm-assistant', $rfq).'"')
        ->not->toMatch('/js-gm-assistant-submit"[^>]*data-part=""/');
});
