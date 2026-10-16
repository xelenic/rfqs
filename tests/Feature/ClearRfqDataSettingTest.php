<?php

use App\Http\Controllers\Admin\SettingsController;
use App\LiveVersion;
use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqCommentAttachment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryAssigned;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    Storage::fake(RfqCommentAttachment::DISK);
});

/**
 * Two RFQs with a part each, a comment with a photo, and an "assigned to
 * you" alert — the RFQ data there is to clear.
 *
 * @return array{riley: User, morgan: User}
 */
function someRfqData(): array
{
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');

    foreach (['RFQ1001', 'RFQ1002'] as $number) {
        $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number]), [1 => $riley]);
        $rfq->refresh()->completeSourcingPart(1, 'Done', $morgan);
    }

    $comment = RfqComment::query()->firstOrFail();
    $path = UploadedFile::fake()->image('site.jpg')->store('rfq-attachments/'.$comment->rfq_id, RfqCommentAttachment::DISK);
    RfqCommentAttachment::factory()->create(['rfq_id' => $comment->rfq_id, 'rfq_comment_id' => $comment->id, 'path' => $path]);
    $morgan->notify(new DataEntryAssigned(Rfq::query()->firstOrFail(), 1, $riley));

    return ['riley' => $riley, 'morgan' => $morgan];
}

function clearRfqData(User $by, array $payload): TestResponse
{
    return test()->actingAs($by)->from(route('admin.settings.edit'))
        ->delete(route('admin.settings.rfq-data.clear'), $payload);
}

it('shows an Admin the Danger Zone, with how much there is to clear', function () {
    someRfqData();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'danger-zone']))->assertOk()
        ->assertSee('data-settings-tab="danger-zone"', false)
        ->assertSee('action="'.route('admin.settings.rfq-data.clear').'"', false)
        ->assertSee('<strong>2</strong> RFQs', false)
        ->assertSee('<strong>2</strong> Sourcing parts', false)
        ->assertSee('<strong>1</strong> photo or file', false)
        ->assertSee('Type <code>'.SettingsController::CLEAR_RFQ_DATA_PHRASE.'</code> to confirm', false);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.settings.edit'))->assertOk()
        ->assertDontSee('data-settings-tab="danger-zone"', false);
});

it('clears every RFQ and everything with it — leaving people, settings and the rest alone', function () {
    Log::spy();
    ['riley' => $riley, 'morgan' => $morgan] = someRfqData();
    Setting::putRfqNumberStart(1670);
    $admin = userWithRole('Admin');
    $version = LiveVersion::current();

    clearRfqData($admin, ['confirmation' => 'DELETE ALL RFQS', 'password' => 'password'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Cleared 2 RFQs and everything with them. The next RFQ will be RFQ1670.')
        ->assertSessionHas('settings_tab', 'danger-zone');

    foreach (['rfqs', 'rfq_user', 'rfq_comments', 'rfq_comment_attachments', 'rfq_steps', 'rfq_returns'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is empty");
    }

    expect(Storage::disk(RfqCommentAttachment::DISK)->allFiles('rfq-attachments'))->toBe([])
        ->and($morgan->notifications()->count())->toBe(0)
        ->and(User::query()->whereKey([$riley->id, $morgan->id, $admin->id])->count())->toBe(3)
        ->and(Setting::rfqNumberStart())->toBe(1670)
        ->and(LiveVersion::current())->not->toBe($version);

    Log::shouldHaveReceived('warning')->with('An Admin cleared all RFQ data.', Mockery::on(fn (array $context) => $context['admin_id'] === $admin->id && $context['rows']['rfqs'] === 2))->once();

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'danger-zone']))
        ->assertSee("There's no RFQ data to clear.", false);
});

it('clears nothing without the phrase typed exactly and their own password', function (array $payload, string $field, string $message) {
    someRfqData();

    clearRfqData(userWithRole('Admin'), $payload)
        ->assertSessionHasErrorsIn('danger_zone', [$field => $message]);

    expect(Rfq::count())->toBe(2);

    // Back on the Danger Zone tab, saying what's wrong.
    test()->actingAs(userWithRole('Admin'))->from(route('admin.settings.edit'))->followingRedirects()
        ->delete(route('admin.settings.rfq-data.clear'), $payload)
        ->assertSee('class="tab-pane fade show active" id="settings-pane-danger-zone"', false);
})->with([
    'no phrase' => [['password' => 'password'], 'confirmation', 'Type DELETE ALL RFQS to confirm.'],
    'the phrase in small letters' => [['confirmation' => 'delete all rfqs', 'password' => 'password'], 'confirmation', 'Type DELETE ALL RFQS exactly — in capitals — to confirm.'],
    'no password' => [['confirmation' => 'DELETE ALL RFQS'], 'password', 'Enter your password to confirm.'],
    'the wrong password' => [['confirmation' => 'DELETE ALL RFQS', 'password' => 'not-it'], 'password', 'That isn\'t your password.'],
]);

it('keeps it to an Admin', function (string $role) {
    someRfqData();

    clearRfqData(userWithRole($role), ['confirmation' => 'DELETE ALL RFQS', 'password' => 'password'])->assertForbidden();

    expect(Rfq::count())->toBe(2);
})->with(['Senior Operations', 'Business Development', 'General Manager']);
