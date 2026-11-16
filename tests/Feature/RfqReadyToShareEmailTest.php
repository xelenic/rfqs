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
 * An RFQ $creator made, split into $parts, every part approved by the
 * General Manager, so it's ready for Business Development to close.
 *
 * @param  array<string, mixed>  $attributes
 */
function readyToShareRfq(User $creator, int $parts = 1, array $attributes = []): Rfq
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
        $rfq->refresh()->approveGmPart($part, userWithRole('General Manager'));
    }

    return $rfq->refresh();
}

it('emails whoever created the RFQ, once it\'s closed, that it\'s ready to share with the customer', function () {
    Notification::fake();
    $creator = tap(userWithRole('Business Development'))->update(['name' => 'Bailey Nguyen']);
    $rfq = readyToShareRfq($creator);

    Notification::assertNothingSent();

    test()->actingAs($creator)->patch(route('admin.rfqs.close', $rfq), ['reference_code' => 'PO-1001'])
        ->assertSessionHas('status', 'RFQ closed.');

    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);
    Notification::assertSentTo($creator, RfqReadyToShare::class, function (RfqReadyToShare $notification, array $channels) use ($creator) {
        $mail = $notification->toMail($creator);

        return $channels === ['mail']
            && $mail->subject === 'Your RFQ1838 is ready to share with customer (WC0191)'
            && $mail->greeting === 'Dear Bailey Nguyen,'
            && $mail->introLines === ['Your RFQ1838 is ready to share with customer (WC0191). Please do the needful to share the final file with them at earliest possible.'];
    });
});

it('emails the creator, not whoever closed it, and only once every part is closed', function () {
    Notification::fake();
    $creator = userWithRole('Business Development');
    $admin = userWithRole('Admin');
    $rfq = readyToShareRfq($creator, parts: 2);

    test()->actingAs($admin)->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1, 'reference_code' => 'PO-1001']);
    Notification::assertNothingSent();

    test()->actingAs($admin)->patch(route('admin.rfqs.close-part', $rfq->refresh()), ['part' => 2, 'reference_code' => 'PO-1002']);

    expect($rfq->refresh()->status)->toBe('Completed');
    Notification::assertSentToTimes($creator, RfqReadyToShare::class, 1);
    Notification::assertNotSentTo($admin, RfqReadyToShare::class);
});

it('leaves the brackets out for an RFQ without a WC number', function () {
    $creator = userWithRole('Business Development');
    $rfq = Rfq::factory()->make(['rfq_number' => 'RFQ1838', 'wc_number' => null, 'created_by' => $creator->id]);

    expect((new RfqReadyToShare($rfq))->toMail($creator)->subject)->toBe('Your RFQ1838 is ready to share with customer');
});

it('emails nobody when the creator\'s account is deactivated or gone, and still closes', function () {
    Notification::fake();
    $deactivated = userWithRole('Business Development');
    $rfq = readyToShareRfq($deactivated);
    $deactivated->forceFill(['deactivated_at' => now()])->save();
    $gone = readyToShareRfq(userWithRole('Business Development'), attributes: ['rfq_number' => 'RFQ1839', 'wc_number' => 'WC0192']);
    $gone->creator->delete();

    $closer = userWithRole('Admin');
    test()->actingAs($closer)->patch(route('admin.rfqs.close', $rfq), ['reference_code' => 'PO-1001']);
    test()->actingAs($closer)->patch(route('admin.rfqs.close', $gone->refresh()), ['reference_code' => 'PO-1002']);

    expect($rfq->refresh()->status)->toBe('Completed')->and($gone->refresh()->status)->toBe('Completed');
    Notification::assertNothingSent();
});

it('still closes the RFQ when the email can\'t be sent, and reports why', function () {
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
    $rfq = readyToShareRfq($creator);

    test()->actingAs($creator)->patch(route('admin.rfqs.close', $rfq), ['reference_code' => 'PO-1001'])
        ->assertSessionHas('status', 'RFQ closed.');

    expect($rfq->refresh()->status)->toBe('Completed');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The mail server is down.');
});
