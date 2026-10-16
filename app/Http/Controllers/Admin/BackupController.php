<?php

namespace App\Http\Controllers\Admin;

use App\BackupManager;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Backups (App\BackupManager): the backups there are — made by hand,
 * or each day and week on the schedule set here — kept a week, each to
 * download, delete or restore from; one downloaded can be uploaded again.
 * Admin only. Restoring takes a typed phrase and the Admin's password, and
 * makes a backup of how things are first.
 */
class BackupController extends Controller
{
    /**
     * What an Admin types to restore a backup.
     */
    public const RESTORE_PHRASE = 'RESTORE';

    /**
     * The biggest backup that can be uploaded, in kilobytes (PHP's own upload
     * limits apply too).
     */
    public const MAX_UPLOAD_KB = 1024 * 1024;

    public function index(Request $request): View
    {
        $this->abortUnlessAdmin($request);

        $backups = BackupManager::all();

        return view('admin.backups.index', [
            'backups' => $backups,
            'schedule' => Setting::backupSchedule(),
            'totalSize' => (int) $backups->sum('size'),
            'latest' => $backups->sortByDesc(fn (array $backup) => $backup['created_at']->getTimestamp())->first(),
        ]);
    }

    /**
     * Back up now — with the uploaded files too, or not.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        try {
            $backup = BackupManager::create('manual', $request->boolean('include_files'), $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Log::info('An Admin made a backup.', ['admin_id' => $request->user()->id, 'backup' => $backup['name']]);

        return back()->with('status', "Backed up — {$backup['name']}.");
    }

    /**
     * Keeps a downloaded backup uploaded again, to restore from.
     */
    public function upload(Request $request): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $request->validateWithBag('upload', [
            'backup' => ['required', 'file', 'extensions:zip', 'max:'.self::MAX_UPLOAD_KB],
        ], [
            'backup.required' => 'Choose a backup file to upload.',
            'backup.extensions' => 'Upload a backup .zip file made by this app.',
            'backup.max' => 'That file is too big to upload.',
        ]);

        try {
            $backup = BackupManager::storeUpload($request->file('backup'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['backup' => $exception->getMessage()], 'upload');
        }

        Log::info('An Admin uploaded a backup.', ['admin_id' => $request->user()->id, 'backup' => $backup['name']]);

        return back()->with('status', "Uploaded — {$backup['name']} is ready to restore.");
    }

    /**
     * When the daily and weekly backups run, and whether they take the
     * uploaded files — Setting::backupSchedule().
     */
    public function updateSchedule(Request $request): RedirectResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validateWithBag('schedule', [
            'daily_time' => ['required', 'date_format:H:i'],
            'weekly_day' => ['required', Rule::in(Setting::WEEKDAYS)],
            'weekly_time' => ['required', 'date_format:H:i'],
        ], [
            'daily_time.*' => 'Give the time as hours and minutes, like 02:00.',
            'weekly_time.*' => 'Give the time as hours and minutes, like 03:00.',
            'weekly_day.*' => 'Pick a day of the week.',
        ]);

        Setting::putBackupSchedule([
            'daily' => ['enabled' => $request->boolean('daily_enabled'), 'time' => $validated['daily_time']],
            'weekly' => ['enabled' => $request->boolean('weekly_enabled'), 'day' => $validated['weekly_day'], 'time' => $validated['weekly_time']],
            'include_files' => $request->boolean('include_files'),
        ]);

        return back()->with('status', 'Backup schedule saved.');
    }

    public function download(Request $request, string $backup): BinaryFileResponse
    {
        $this->abortUnlessAdmin($request);
        abort_unless(BackupManager::find($backup), 404);

        return response()->download(BackupManager::path($backup), $backup);
    }

    public function destroy(Request $request, string $backup): RedirectResponse
    {
        $this->abortUnlessAdmin($request);
        abort_unless(BackupManager::delete($backup), 404);

        Log::info('An Admin deleted a backup.', ['admin_id' => $request->user()->id, 'backup' => $backup]);

        return back()->with('status', "Deleted {$backup}.");
    }

    /**
     * Puts everything back as it was in the backup — after the Admin types
     * RESTORE_PHRASE and their password. A backup of now is made first.
     */
    public function restore(Request $request, string $backup): RedirectResponse
    {
        $this->abortUnlessAdmin($request);
        abort_unless(BackupManager::find($backup), 404);

        $request->validateWithBag('restore', [
            'confirmation' => ['required', 'string', Rule::in([self::RESTORE_PHRASE])],
            'password' => ['required', 'current_password'],
        ], [
            'confirmation.required' => 'Type '.self::RESTORE_PHRASE.' to confirm.',
            'confirmation.in' => 'Type '.self::RESTORE_PHRASE.' exactly — in capitals — to confirm.',
            'password.required' => 'Enter your password to confirm.',
            'password.current_password' => 'That isn\'t your password.',
        ]);

        $admin = $request->user();

        try {
            $restored = BackupManager::restore($backup, $admin);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Log::warning('An Admin restored a backup.', ['admin_id' => $admin->id, 'admin' => $admin->email, 'backup' => $backup, 'restored' => $restored]);

        return redirect()->route('admin.backups.index')->with('status', 'Restored '.$backup.' — '
            .number_format($restored['rows']).' rows in '.$restored['tables'].' tables'
            .($restored['files'] > 0 ? ' and '.number_format($restored['files']).' files' : '')
            .". How things were just before is kept as {$restored['safety']}.");
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can manage backups.');
    }
}
