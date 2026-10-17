<?php

use App\BackupManager;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    Storage::fake('local');
});

/**
 * The tables and files a backup holds, read back from its zip.
 *
 * @return array{manifest: array<string, mixed>, entries: list<string>}
 */
function backupContents(string $name): array
{
    $zip = new ZipArchive;
    $zip->open(BackupManager::path($name));
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $zip->close();

    return ['manifest' => $manifest, 'entries' => $entries];
}

function restoreBackup(User $admin, string $name, array $payload = ['confirmation' => 'RESTORE', 'password' => 'password']): TestResponse
{
    return test()->actingAs($admin)->from(route('admin.backups.index'))
        ->post(route('admin.backups.restore', $name), $payload);
}

/**
 * Ages a backup's file, as if it arrived $days ago.
 */
function ageBackup(string $name, int $days): void
{
    touch(BackupManager::path($name), CarbonImmutable::now()->subDays($days)->getTimestamp());
    clearstatcache();
}

it('shows an Admin the Backups page — and nobody else', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.backups.index'))->assertOk()
        ->assertSee('Back up now')
        ->assertSee('Automatic backups')
        ->assertSee('No backups yet')
        ->assertSee('href="'.route('admin.backups.index').'"', false);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.backups.index'))->assertForbidden();
    test()->actingAs(userWithRole('Senior Operations'))->post(route('admin.backups.store'))->assertForbidden();
});

it('backs up every table of the app\'s data — not the cache, sessions or jobs — with the uploaded files if asked', function () {
    $admin = userWithRole('Admin');
    Rfq::factory()->count(2)->create();
    Storage::disk('local')->put('avatars/me.jpg', 'photo');
    Storage::disk('local')->put('rfq-attachments/1/site.jpg', 'file');

    test()->actingAs($admin)->post(route('admin.backups.store'), ['include_files' => '1'])
        ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'Backed up — '));

    $backup = BackupManager::all()->sole();
    ['manifest' => $manifest, 'entries' => $entries] = backupContents($backup['name']);

    expect($backup)->toMatchArray(['type' => 'manual', 'created_by' => $admin->name, 'includes_files' => true, 'files' => 2, 'valid' => true, 'compatibility' => 'same'])
        ->and($manifest['tables']['rfqs'])->toBe(2)
        ->and($manifest['tables'])->toHaveKeys(['users', 'settings', 'rfq_user', 'migrations'])
        ->and($manifest['tables'])->not->toHaveKeys(['cache', 'sessions', 'jobs', 'failed_jobs'])
        ->and($entries)->toContain('database/rfqs.jsonl', 'files/local/avatars/me.jpg', 'files/local/rfq-attachments/1/site.jpg');

    // Without the files: just the data.
    test()->actingAs($admin)->post(route('admin.backups.store'), ['include_files' => '0']);
    $dataOnly = BackupManager::all()->firstWhere('includes_files', false);

    expect(backupContents($dataOnly['name'])['entries'])->each->not->toStartWith('files/');
});

it('lets an Admin download and delete a backup — and nothing outside the backups folder', function () {
    $admin = userWithRole('Admin');
    $backup = BackupManager::create('manual', false, $admin);

    test()->actingAs($admin)->get(route('admin.backups.download', $backup['name']))
        ->assertOk()
        ->assertDownload($backup['name']);

    test()->actingAs($admin)->delete(route('admin.backups.destroy', $backup['name']))
        ->assertSessionHas('status', "Deleted {$backup['name']}.");

    expect(BackupManager::all())->toBeEmpty();

    test()->actingAs($admin)->get(route('admin.backups.download', $backup['name']))->assertNotFound();
    test()->actingAs($admin)->get('/admin/backups/..%2F..%2F.env/download')->assertNotFound();
    expect(BackupManager::find('../../.env'))->toBeNull();
});

it('restores everything as it was — data, settings and files — after a backup of now', function () {
    $admin = userWithRole('Admin');
    $kept = Rfq::factory()->create(['rfq_number' => 'RFQ1001']);
    Setting::put('company_name', 'Before Ltd');
    Storage::disk('local')->put('avatars/me.jpg', 'old photo');
    $backup = BackupManager::create('manual', true, $admin);

    // Things change after.
    $kept->delete();
    Rfq::factory()->create(['rfq_number' => 'RFQ1002']);
    Setting::put('company_name', 'After Ltd');
    Storage::disk('local')->delete('avatars/me.jpg');
    Storage::disk('local')->put('avatars/new.jpg', 'new photo');

    restoreBackup($admin, $backup['name'])
        ->assertRedirect(route('admin.backups.index'))
        ->assertSessionHas('status', fn (string $status) => str_starts_with($status, "Restored {$backup['name']} — ") && str_contains($status, 'How things were just before is kept as '));

    expect(Rfq::query()->pluck('rfq_number')->all())->toBe(['RFQ1001'])
        ->and(Setting::get('company_name'))->toBe('Before Ltd')
        ->and(Storage::disk('local')->get('avatars/me.jpg'))->toBe('old photo')
        ->and(Storage::disk('local')->exists('avatars/new.jpg'))->toBeFalse()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();

    // How it was just before is kept, to undo it with.
    $safety = BackupManager::all()->firstWhere('type', 'safety');
    expect($safety)->not->toBeNull()
        ->and(backupContents($safety['name'])['manifest']['tables']['rfqs'])->toBe(1);
});

it('restores nothing without RESTORE typed and the Admin\'s own password', function (array $payload, string $field, string $message) {
    $admin = userWithRole('Admin');
    $backup = BackupManager::create('manual', false, $admin);
    Rfq::factory()->create();

    restoreBackup($admin, $backup['name'], $payload + ['backup_name' => $backup['name']])
        ->assertSessionHasErrorsIn('restore', [$field => $message]);

    expect(Rfq::count())->toBe(1)
        ->and(BackupManager::all())->toHaveCount(1);
})->with([
    'no phrase' => [['password' => 'password'], 'confirmation', 'Type RESTORE to confirm.'],
    'in small letters' => [['confirmation' => 'restore', 'password' => 'password'], 'confirmation', 'Type RESTORE exactly — in capitals — to confirm.'],
    'the wrong password' => [['confirmation' => 'RESTORE', 'password' => 'nope'], 'password', 'That isn\'t your password.'],
]);

it('won\'t restore a backup made by a newer version of the app', function () {
    $admin = userWithRole('Admin');
    $backup = BackupManager::create('manual', false, $admin);

    // Rewrite its manifest as if a later update had been through it.
    $zip = new ZipArchive;
    $zip->open(BackupManager::path($backup['name']));
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $manifest['migrations'][] = '2099_01_01_000000_from_the_future';
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->close();

    expect(BackupManager::find($backup['name'])['compatibility'])->toBe('newer');

    restoreBackup($admin, $backup['name'])
        ->assertSessionHas('error', 'That backup was made by a newer version of the app — update this one first.');
});

it('takes back a downloaded backup to restore from — and refuses a zip that isn\'t one', function () {
    $admin = userWithRole('Admin');
    $made = BackupManager::create('daily', false);
    $copy = UploadedFile::fake()->createWithContent('backup.zip', file_get_contents(BackupManager::path($made['name'])));
    BackupManager::delete($made['name']);

    test()->actingAs($admin)->post(route('admin.backups.upload'), ['backup' => $copy])
        ->assertSessionHas('status', fn (string $status) => str_ends_with($status, 'is ready to restore.'));

    expect(BackupManager::all()->sole())->toMatchArray(['type' => 'uploaded', 'valid' => true]);

    $notOurs = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $notOurs->open($path, ZipArchive::OVERWRITE);
    $notOurs->addFromString('hello.txt', 'hi');
    $notOurs->close();

    test()->actingAs($admin)->from(route('admin.backups.index'))
        ->post(route('admin.backups.upload'), ['backup' => UploadedFile::fake()->createWithContent('other.zip', file_get_contents($path))])
        ->assertSessionHasErrorsIn('upload', ['backup' => "That file isn't a backup made by this app."]);

    expect(BackupManager::all())->toHaveCount(1);
});

it('saves when the automatic backups run', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->patch(route('admin.backups.schedule'), [
        'daily_enabled' => '1', 'daily_time' => '01:30',
        'weekly_enabled' => '0', 'weekly_day' => 'friday', 'weekly_time' => '04:00',
        'include_files' => '0',
    ])->assertSessionHas('status', 'Backup schedule saved.');

    expect(Setting::backupSchedule())->toBe([
        'daily' => ['enabled' => true, 'time' => '01:30'],
        'weekly' => ['enabled' => false, 'day' => 'friday', 'time' => '04:00'],
        'include_files' => false,
    ]);

    test()->actingAs($admin)->from(route('admin.backups.index'))->patch(route('admin.backups.schedule'), [
        'daily_time' => '25:00', 'weekly_day' => 'someday', 'weekly_time' => '03:00',
    ])->assertSessionHasErrorsIn('schedule', ['daily_time', 'weekly_day']);
});

it('makes the day\'s and week\'s backups once their time comes — once each — and prunes those older than a week', function () {
    $zone = Setting::timezone();
    // This Sunday — today, if it is one — never a week or more ahead, or the
    // backups just made (dated today by their files) would be pruned as
    // already a week old. Daily at 02:00 and weekly at 03:00 by default.
    $today = CarbonImmutable::now($zone);
    $sunday = $today->isSunday() ? $today : $today->next(CarbonImmutable::SUNDAY);

    test()->travelTo($sunday->setTime(1, 59));
    test()->artisan('backup:scheduled')->assertSuccessful();
    expect(BackupManager::all())->toBeEmpty();

    test()->travelTo($sunday->setTime(2, 5));
    test()->artisan('backup:scheduled')->assertSuccessful();
    test()->artisan('backup:scheduled')->assertSuccessful();
    expect(BackupManager::all()->pluck('type')->all())->toBe(['daily']);

    test()->travelTo($sunday->setTime(3, 0));
    test()->artisan('backup:scheduled')->assertSuccessful();
    expect(BackupManager::all()->pluck('type')->sort()->values()->all())->toBe(['daily', 'weekly']);

    // A week on, those are gone.
    $old = BackupManager::all()->pluck('name');
    $old->each(fn (string $name) => ageBackup($name, 8));
    test()->artisan('backup:scheduled')->assertSuccessful();

    expect(BackupManager::all()->pluck('name')->intersect($old))->toBeEmpty();
});

it('makes no automatic backup with the schedule off', function () {
    Setting::putBackupSchedule([
        'daily' => ['enabled' => false, 'time' => '02:00'],
        'weekly' => ['enabled' => false, 'day' => 'sunday', 'time' => '03:00'],
        'include_files' => true,
    ]);
    test()->travelTo(CarbonImmutable::now(Setting::timezone())->next(CarbonImmutable::SUNDAY)->setTime(23, 0));

    test()->artisan('backup:scheduled')->assertSuccessful();

    expect(BackupManager::all())->toBeEmpty();
});
