<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\RfqCommentAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }

    Storage::fake(RfqCommentAttachment::DISK);
});

it('keeps the photos and files posted with a comment, and shows them under it', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), [
        'body' => 'Site photos and the client\'s spec',
        'attachments' => [
            UploadedFile::fake()->image('roof.jpg', 640, 480),
            UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf'),
        ],
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Comment posted.');

    $comment = $rfq->comments()->sole();
    [$photo, $pdf] = $comment->attachments->all();

    expect($comment->attachments)->toHaveCount(2)
        ->and($photo)->toMatchArray(['original_name' => 'roof.jpg', 'mime_type' => 'image/jpeg', 'rfq_id' => $rfq->id])
        ->and($photo->isImage())->toBeTrue()
        ->and($pdf->isImage())->toBeFalse();
    Storage::disk(RfqCommentAttachment::DISK)->assertExists([$photo->path, $pdf->path]);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('<img src="'.$photo->url().'" alt="roof.jpg"', false)
        ->assertSee('href="'.e($pdf->url(download: true)).'"', false)
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('name="attachments[]"', false);

    // A photo is shown in place; anything else is downloaded.
    test()->actingAs($ops)->get($photo->url())->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(test()->actingAs($ops)->get($pdf->url())->assertOk()->headers->get('Content-Disposition'))
        ->toStartWith('attachment;');
});

it('keeps files sent with a reason on the comment that reason is posted as', function () {
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);

    test()->actingAs($dataEntry)->patch(route('admin.rfqs.return-sourcing', $rfq), [
        'part' => 1,
        'reason' => 'The quote in the photo is for the wrong model',
        'attachments' => [UploadedFile::fake()->image('quote.png')],
    ])->assertSessionHasNoErrors();

    $return = $rfq->comments()->where('action', 'returned_to_sourcing')->sole();

    expect($return->attachments->pluck('original_name')->all())->toBe(['quote.png']);
});

it('puts a reject\'s files on its own reason, not on the parts it sends back first', function () {
    $gm = userWithRole('General Manager');
    $rfq = splitAmong(Rfq::factory()->create(['stage' => 'gm_review']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), [
        'target_stage' => 'sourcing',
        'reason' => 'Prices are out of date — see the marked-up sheet',
        'attachments' => [UploadedFile::fake()->create('marked-up.xlsx', 40)],
    ])->assertSessionHasNoErrors();

    expect($rfq->comments()->where('action', 'rejected')->sole()->attachments->pluck('original_name')->all())->toBe(['marked-up.xlsx'])
        ->and(RfqCommentAttachment::query()->count())->toBe(1);
});

it('shows the files a sent-back RFQ came with on the Returns page', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ19001']);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), [
        'reason' => 'Which of these two buildings is it?',
        'attachments' => [UploadedFile::fake()->image('site-map.jpg')],
    ])->assertSessionHasNoErrors();

    test()->actingAs(userWithRole('Business Development'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Which of these two buildings is it?')
        ->assertSee('alt="site-map.jpg"', false);
});

it('turns away a file that isn\'t a photo or a document, or too many at once — posting nothing', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), [
        'body' => 'Script',
        'attachments' => [UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')],
    ])->assertSessionHas('error', 'Attach photos (JPG, PNG, GIF, WebP), PDFs, or Word, Excel, CSV or text files.');

    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), [
        'body' => 'Six of them',
        'attachments' => array_map(fn (int $n) => UploadedFile::fake()->image("p{$n}.jpg"), range(1, 6)),
    ])->assertSessionHas('error', 'Attach up to 5 files at a time.');

    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), [
        'body' => 'Too big',
        'attachments' => [UploadedFile::fake()->create('scan.pdf', 11 * 1024, 'application/pdf')],
    ])->assertSessionHas('error', 'Keep each attachment under 10 MB.');

    // Nor does the action go ahead without them.
    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), [
        'reason' => 'Need more',
        'attachments' => [UploadedFile::fake()->create('run.exe', 4)],
    ])->assertSessionHas('error');

    expect(RfqComment::query()->count())->toBe(0)
        ->and($rfq->refresh()->isReturnedToBusinessDevelopment())->toBeFalse();
});

it('serves a file only to those who can see RFQs', function () {
    $attachment = RfqCommentAttachment::factory()->create();
    Storage::disk(RfqCommentAttachment::DISK)->put($attachment->path, 'jpeg bytes');

    Role::findOrCreate('HR Manager');
    test()->actingAs(User::factory()->create()->assignRole('HR Manager'))->get($attachment->url())->assertForbidden();
    test()->actingAs(userWithRole('Sourcing'))->get($attachment->url())->assertOk();
});

it('takes a comment\'s files — and its replies\' — with it when it\'s deleted', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), ['body' => 'Photo', 'attachments' => [UploadedFile::fake()->image('a.jpg')]]);
    $comment = $rfq->comments()->sole();
    test()->actingAs($ops)->post(route('admin.rfqs.comments.store', $rfq), ['body' => 'Reply', 'parent_id' => $comment->id, 'attachments' => [UploadedFile::fake()->image('b.jpg')]]);

    $paths = RfqCommentAttachment::query()->pluck('path')->all();
    Storage::disk(RfqCommentAttachment::DISK)->assertExists($paths);

    test()->actingAs($ops)->delete(route('admin.rfqs.comments.destroy', [$rfq, $comment]))->assertSessionHas('status', 'Comment deleted.');

    Storage::disk(RfqCommentAttachment::DISK)->assertMissing($paths);
    expect(RfqCommentAttachment::query()->count())->toBe(0);
});
