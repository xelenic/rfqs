<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('Admin');

    // A folder of our own — never the real storage/logs.
    $this->logs = sys_get_temp_dir().'/rfqms-logs-'.Str::random(8);
    File::ensureDirectoryExists($this->logs);
    config(['logging.channels.single.path' => $this->logs.'/laravel.log']);

    File::put($this->logs.'/laravel.log', implode("\n", [
        '[2026-10-05 03:00:00] local.INFO: Attendance sheet approved {"sheet":4}',
        '[2026-10-05 04:15:00] local.ERROR: View [auth.login] not found. {"exception":"[object] (InvalidArgumentException(code: 0))"}',
        '[stacktrace]',
        '#0 /app/vendor/laravel/framework/src/Illuminate/View/FileViewFinder.php(78): find()',
        '#1 {main}',
        '"}',
        "[2026-10-05 05:30:00] local.DEBUG: From: RFQMS <hello@example.com>\r",
        "To: morgan@company.lk\r",
        "Subject: Your RFQMS account is ready\r",
        '',
        '[2026-10-05 06:00:00] local.WARNING: Slow query on rfq_user',
        '',
    ]));
    File::put($this->logs.'/browser.log', "[2026-10-05 02:00:00] local.DEBUG: document click\n");
});

afterEach(function () {
    File::deleteDirectory($this->logs);
});

/**
 * The Logs page for an Admin, with $query.
 */
function logsPage(array $query = []): string
{
    return test()->actingAs(userWithRole('Admin'))->get(route('admin.logs.index', $query))->assertOk()->getContent();
}

it('lists the newest entries first, each with its level and time in the working hours\' zone', function () {
    $html = logsPage(['file' => 'laravel.log']);

    expect(strpos($html, 'Slow query on rfq_user'))->toBeLessThan(strpos($html, 'Attendance sheet approved'))
        // 06:00 UTC is 11:30 in Colombo.
        ->and($html)->toContain('Oct 05, 11:30:00 AM')
        ->toContain('<span class="badge badge-soft-danger">Error</span>')
        ->toContain('All <span class="rfq-tab-count">4</span>')
        // The stack trace a click away.
        ->toContain('#0 /app/vendor/laravel/framework/src/Illuminate/View/FileViewFinder.php(78)')
        // An email the log mailer wrote, read as one.
        ->toContain('Your RFQMS account is ready')
        ->toContain('to morgan@company.lk');
});

it('narrows to a level, and to a search', function () {
    expect(logsPage(['file' => 'laravel.log', 'level' => 'error']))
        ->toContain('View [auth.login] not found.')
        ->not->toContain('Slow query on rfq_user');

    expect(logsPage(['file' => 'laravel.log', 'search' => 'slow QUERY']))
        ->toContain('Slow query on rfq_user')
        ->not->toContain('View [auth.login] not found.');
});

it('offers each log file, and downloads the one picked — nothing outside the logs', function () {
    expect(logsPage())->toContain('<option value="browser.log"')->toContain('<option value="laravel.log"');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.logs.download', ['file' => 'laravel.log']))
        ->assertOk()
        ->assertDownload('laravel.log');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.logs.download', ['file' => '../../.env']))->assertNotFound();
    expect(logsPage(['file' => '../../.env']))->not->toContain('APP_KEY');
});

it('keeps the logs to an Admin', function () {
    foreach (['Senior Operations', 'HR Manager', 'Sourcing'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.logs.index'))->assertForbidden();
        test()->actingAs(userWithRole($role))->get(route('admin.logs.download', ['file' => 'laravel.log']))->assertForbidden();
    }

    expect(test()->actingAs(userWithRole('Sourcing'))->get(route('admin.dashboard'))->getContent())->not->toContain(route('admin.logs.index'))
        ->and(test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->getContent())->toContain(route('admin.logs.index'));
});
