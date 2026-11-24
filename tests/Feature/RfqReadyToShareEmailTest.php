<?php

use App\Models\Rfq;
use App\Models\User;
use App\Notifications\RfqReadyToShare;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An RFQ $creator made, split into $parts, every part through GM Assistant,
 * so it's waiting on the General Manager's approval.
 *
 * @param  array<string, mixed>  $attributes
 */
function rfqAwaitingGm(User $creator, int $parts = 1, array $attributes = []): Rfq
{
    $sourcing = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create($attributes + ['rfq_number' => 'RFQ1838', 'wc_number' => 'WC0191', 'created_by' => $creator->id]), array_fill(1, $parts, $sourcing));

    foreach (range(1, $parts) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('HBDs Operations'));
        $rfq->refresh()->approveHeadOfBdPart($part, userWithRole('Head of Business Development'));
        $rfq->refresh()->recordGmAssistantPart($part, userWithRole('GMs Operations'));
    }

    return $rfq->refresh();
}

it('emails whoever created the RFQ, once the General Manager approves it, that it\'s ready to share with the customer — and not again when it\'s closed', function () {
    Notification::fake();
    $creator = tap(userWithRole('Business Development'))->update(['name' => 'Bailey Nguyen']);
    $rfq = rfqAwaitingGm($creator);

    Notification::assertNothingSent();

    test()->actingAs(userWithRole('General Manager'))->patch(route('admin.rfqs.approve-gm', $rfq))
        ->assertSessionHas('status', 'Approved — ready for Business Development to close.');

    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);
    Notification::assertSentTo($creator, RfqReadyToShare::class, function (RfqReadyToShare $notification, array $channels) use ($creator) {
        $mail = $notification->toMail($creator);

        return $channels === ['mail']
            && $mail->subject === 'Your RFQ1838 is ready to share with customer (WC0191)'
            && $mail->greeting === 'Dear Bailey Nguyen,'
            && $mail->introLines === ['Your RFQ1838 is ready to share with customer (WC0191). Please do the needful to share the final file with them at earliest possible.'];
    });

    test()->actingAs($creator)->patch(route('admin.rfqs.close', $rfq->refresh()), ['reference_code' => 'PO-1001'])
        ->assertSessionHas('status', 'RFQ closed.');

    expect($rfq->refresh()->status)->toBe('Completed');
    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);
});

it('emails the creator, not the General Manager, and only once every part is approved', function () {
    Notification::fake();
    $creator = userWithRole('Business Development');
    $gm = userWithRole('General Manager');
    $rfq = rfqAwaitingGm($creator, parts: 2);

    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1]);
    Notification::assertNothingSent();

    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm-part', $rfq->refresh()), ['part' => 2]);

    expect($rfq->refresh()->stage)->toBe('bd_closing');
    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);
    Notification::assertNotSentTo($gm, RfqReadyToShare::class);
});

it('emails the creator again once the General Manager approves what Business Development sent back to them', function () {
    Notification::fake();
    $creator = userWithRole('Business Development');
    $gm = userWithRole('General Manager');
    $rfq = rfqAwaitingGm($creator);

    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm', $rfq));
    test()->actingAs($creator)->patch(route('admin.rfqs.reject-bd', $rfq->refresh()), ['part' => 1, 'target_stage' => 'gm_review', 'reason' => 'Client asked for a lower price'])
        ->assertSessionHasNoErrors();
    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);

    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm-part', $rfq->refresh()), ['part' => 1]);

    expect($rfq->refresh()->stage)->toBe('bd_closing');
    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 2);
});

it('leaves the brackets out for an RFQ without a WC number', function () {
    $creator = userWithRole('Business Development');
    $rfq = Rfq::factory()->make(['rfq_number' => 'RFQ1838', 'wc_number' => null, 'created_by' => $creator->id]);

    expect((new RfqReadyToShare($rfq))->toMail($creator)->subject)->toBe('Your RFQ1838 is ready to share with customer');
});

it('emails nobody when the creator\'s account is deactivated or gone, and still approves', function () {
    Notification::fake();
    $deactivated = userWithRole('Business Development');
    $rfq = rfqAwaitingGm($deactivated);
    $deactivated->forceFill(['deactivated_at' => now()])->save();
    $gone = rfqAwaitingGm(userWithRole('Business Development'), attributes: ['rfq_number' => 'RFQ1839', 'wc_number' => 'WC0192']);
    $gone->creator->delete();

    $gm = userWithRole('General Manager');
    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm', $rfq));
    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm', $gone->refresh()));

    expect($rfq->refresh()->stage)->toBe('bd_closing')->and($gone->refresh()->stage)->toBe('bd_closing');
    Notification::assertNothingSent();
});

it('still approves the RFQ when the email can\'t be sent, and reports why', function () {
    Exceptions::fake();
    // A mail server that's down: the mail channel fails.
    app(ChannelManager::class)->extend('mail', fn () => new class
    {
        public function send(object $notifiable, BaseNotification $notification): void
        {
            throw new RuntimeException('The mail server is down.');
        }
    });
    $creator = userWithRole('Business Development');
    $rfq = rfqAwaitingGm($creator);

    test()->actingAs(userWithRole('General Manager'))->patch(route('admin.rfqs.approve-gm', $rfq))
        ->assertSessionHas('status', 'Approved — ready for Business Development to close.');

    expect($rfq->refresh()->stage)->toBe('bd_closing');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The mail server is down.');
});
