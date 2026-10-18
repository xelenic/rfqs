{{--
    Admin → Backups (Admin\BackupController, App\BackupManager): the backups
    there are, latest first — each to download, restore from or delete — and
    a backup made now, with or without the uploaded files; one downloaded
    before can be uploaded to restore from. Beside them, when the daily and
    weekly backups run. Every backup is kept RETENTION_DAYS. Restoring asks
    for a typed phrase and the Admin's password, in one popup pointed at the
    backup picked.

    Expects: $backups (BackupManager::all()), $schedule
    (Setting::backupSchedule()), $totalSize, $latest (the newest, or null).
--}}
@extends('layouts.app')

@section('title', 'Backups')

@section('content')
    @php
        $timezone = \App\Models\Setting::timezone();
        $now = \Carbon\CarbonImmutable::now($timezone);
        $retention = \App\BackupManager::RETENTION_DAYS;
        $sizeLabel = fn (int $bytes) => match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            default => number_format(max(1, $bytes) / 1024, 0).' KB',
        };
        $typeBadges = ['manual' => 'badge-soft-primary', 'daily' => 'badge-soft-success', 'weekly' => 'badge-soft-info', 'safety' => 'badge-soft-warning', 'uploaded' => 'badge-soft-secondary'];

        // When each automatic backup next runs.
        $nextDaily = $schedule['daily']['enabled'] ? $now->setTimeFromTimeString($schedule['daily']['time']) : null;
        $nextDaily = $nextDaily?->lte($now) ? $nextDaily->addDay() : $nextDaily;
        $nextWeekly = $schedule['weekly']['enabled'] ? $now->setTimeFromTimeString($schedule['weekly']['time']) : null;
        if ($nextWeekly) {
            while (strtolower($nextWeekly->englishDayOfWeek) !== $schedule['weekly']['day'] || $nextWeekly->lte($now)) {
                $nextWeekly = $nextWeekly->addDay()->setTimeFromTimeString($schedule['weekly']['time']);
            }
        }
        $nextRun = collect([$nextDaily, $nextWeekly])->filter()->sort()->first();
        $nextRunLabel = $nextDaily && $nextRun?->eq($nextDaily) ? 'Daily backup' : 'Weekly backup';

        $restoreFailed = $errors->getBag('restore')->any();
        $uploadFailed = $errors->getBag('upload')->any();
        $scheduleFailed = $errors->getBag('schedule')->any();
    @endphp

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <p class="text-muted-soft small mb-0">
            Every table of the app's data — and, if you choose, the uploaded photos and files. Each backup is kept {{ $retention }} days, then deleted by itself.
        </p>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#backupUploadModal">
                <i class="bi bi-upload"></i> Upload backup
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#backupNowModal">
                <i class="bi bi-cloud-arrow-up"></i> Back up now
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Backups kept', 'value' => $backups->count(), 'caption' => "For {$retention} days each", 'icon' => 'bi-archive', 'tone' => ''],
            ['label' => 'Storage used', 'value' => $backups->isEmpty() ? '0 KB' : $sizeLabel($totalSize), 'caption' => 'storage/app/private/backups', 'icon' => 'bi-hdd', 'tone' => 'teal'],
            ['label' => 'Last backup', 'value' => $latest ? $latest['created_at']->format('M d, Y g:i A') : 'None yet', 'caption' => $latest ? \App\BackupManager::TYPES[$latest['type']] : 'Back up now to make one', 'icon' => 'bi-clock-history', 'tone' => 'success'],
            ['label' => 'Next automatic', 'value' => $nextRun ? $nextRun->format('D g:i A') : 'Off', 'caption' => $nextRun ? $nextRunLabel.' · in '.$nextRun->diffForHumans(null, true) : 'Turn on the schedule', 'icon' => 'bi-calendar-check', 'tone' => 'violet'],
        ] as $kpi)
            <div class="col-6 col-xl-3">
                <div class="stat-card kpi-card">
                    <div class="kpi-card-head">
                        <span class="stat-label">{{ $kpi['label'] }}</span>
                        <span class="stat-icon {{ $kpi['tone'] ? 'stat-icon-'.$kpi['tone'] : '' }}"><i class="bi {{ $kpi['icon'] }}"></i></span>
                    </div>
                    <div class="stat-value">{{ $kpi['value'] }}</div>
                    <div class="stat-caption">{{ $kpi['caption'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        {{-- The backups, latest first. --}}
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Backups <span class="badge badge-soft-primary ms-1">{{ $backups->count() }}</span></span>
                </div>
                @if ($backups->isEmpty())
                    <div class="card-body text-center py-5">
                        <i class="bi bi-archive fs-2 text-muted-soft"></i>
                        <p class="text-muted-soft mb-0 mt-2">No backups yet — back up now, or wait for the next automatic one.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 backup-table">
                            <thead>
                                <tr>
                                    <th>Backup</th>
                                    <th>Type</th>
                                    <th>Contains</th>
                                    <th class="text-end">Size</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backups as $backup)
                                    @php
                                        $goesIn = $backup['stored_at']->addDays($retention);
                                        $canRestore = $backup['valid'] && $backup['compatibility'] !== 'newer';
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $backup['created_at']->format('M d, Y g:i A') }}</div>
                                            <div class="small text-muted-soft text-truncate" style="max-width: 320px;" title="{{ $backup['name'] }}">{{ $backup['name'] }}</div>
                                            <div class="small text-muted-soft">
                                                {{ $backup['created_by'] ? 'By '.$backup['created_by'].' · ' : '' }}Deleted {{ $goesIn->isPast() ? 'soon' : 'in '.$goesIn->diffForHumans(null, true) }}
                                            </div>
                                        </td>
                                        <td><span class="badge {{ $typeBadges[$backup['type']] ?? 'badge-soft-secondary' }}">{{ \App\BackupManager::TYPES[$backup['type']] ?? ucfirst($backup['type']) }}</span></td>
                                        <td class="small">
                                            @if ($backup['valid'])
                                                <div><i class="bi bi-database text-muted-soft"></i> {{ number_format($backup['rows']) }} rows · {{ $backup['tables'] }} tables</div>
                                                <div>
                                                    @if ($backup['includes_files'])
                                                        <i class="bi bi-images text-muted-soft"></i> {{ number_format($backup['files']) }} {{ \Illuminate\Support\Str::plural('file', $backup['files']) }}
                                                    @else
                                                        <span class="text-muted-soft"><i class="bi bi-images"></i> No files</span>
                                                    @endif
                                                </div>
                                                @if ($backup['compatibility'] === 'older')
                                                    <div class="text-warning-emphasis" title="Made before the app was updated. It restores, but anything those updates set up (like new permissions) may need setting up again.">
                                                        <i class="bi bi-info-circle"></i> From before {{ $backup['updates_since'] }} {{ \Illuminate\Support\Str::plural('update', $backup['updates_since']) }}
                                                    </div>
                                                @elseif ($backup['compatibility'] === 'newer')
                                                    <div class="text-danger"><i class="bi bi-x-circle"></i> Made by a newer version</div>
                                                @endif
                                            @else
                                                <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Not a backup made by this app</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">{{ $sizeLabel($backup['size']) }}</td>
                                        <td class="text-end text-nowrap">
                                            <a href="{{ route('admin.backups.download', $backup['name']) }}" class="btn btn-sm btn-outline-secondary" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-primary js-restore-backup" title="Restore" @disabled(! $canRestore)
                                                    data-bs-toggle="modal" data-bs-target="#backupRestoreModal"
                                                    data-name="{{ $backup['name'] }}"
                                                    data-action="{{ route('admin.backups.restore', $backup['name']) }}"
                                                    data-made="{{ $backup['created_at']->format('M d, Y g:i A') }}"
                                                    data-files="{{ $backup['includes_files'] ? '1' : '0' }}"
                                                    data-updates="{{ $backup['compatibility'] === 'older' ? $backup['updates_since'] : 0 }}">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                            <form action="{{ route('admin.backups.destroy', $backup['name']) }}" method="POST" class="d-inline" data-confirm="Delete the backup from {{ $backup['created_at']->format('M d, Y g:i A') }}? It can't be brought back.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- When the automatic backups run. --}}
        <div class="col-xl-4">
            <div class="card mb-4" id="backup-schedule">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-week"></i> Automatic backups
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.backups.schedule') }}" novalidate>
                        @csrf
                        @method('PATCH')

                        <div class="backup-schedule-row">
                            <div class="form-check form-switch mb-2">
                                <input type="hidden" name="daily_enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="daily_enabled" id="backup-daily" value="1"
                                       @checked($scheduleFailed ? old('daily_enabled') === '1' : $schedule['daily']['enabled'])>
                                <label class="form-check-label fw-semibold" for="backup-daily">Daily backup</label>
                            </div>
                            <label class="form-label small text-muted-soft" for="backup-daily-time">Every day at</label>
                            <input type="time" name="daily_time" id="backup-daily-time"
                                   class="form-control @error('daily_time', 'schedule') is-invalid @enderror"
                                   value="{{ $scheduleFailed ? old('daily_time') : $schedule['daily']['time'] }}" required>
                            @error('daily_time', 'schedule')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="backup-schedule-row">
                            <div class="form-check form-switch mb-2">
                                <input type="hidden" name="weekly_enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="weekly_enabled" id="backup-weekly" value="1"
                                       @checked($scheduleFailed ? old('weekly_enabled') === '1' : $schedule['weekly']['enabled'])>
                                <label class="form-check-label fw-semibold" for="backup-weekly">Weekly backup</label>
                            </div>
                            <div class="row g-2">
                                <div class="col-7">
                                    <label class="form-label small text-muted-soft" for="backup-weekly-day">Every</label>
                                    <select name="weekly_day" id="backup-weekly-day" class="form-select @error('weekly_day', 'schedule') is-invalid @enderror">
                                        @foreach (\App\Models\Setting::WEEKDAYS as $day)
                                            <option value="{{ $day }}" @selected(($scheduleFailed ? old('weekly_day') : $schedule['weekly']['day']) === $day)>{{ ucfirst($day) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-5">
                                    <label class="form-label small text-muted-soft" for="backup-weekly-time">At</label>
                                    <input type="time" name="weekly_time" id="backup-weekly-time"
                                           class="form-control @error('weekly_time', 'schedule') is-invalid @enderror"
                                           value="{{ $scheduleFailed ? old('weekly_time') : $schedule['weekly']['time'] }}" required>
                                </div>
                            </div>
                            @error('weekly_time', 'schedule')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="include_files" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="include_files" id="backup-auto-files" value="1"
                                   @checked($scheduleFailed ? old('include_files') === '1' : $schedule['include_files'])>
                            <label class="form-check-label" for="backup-auto-files">Include uploaded photos and files</label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2"></i> Save schedule</button>
                    </form>

                    <p class="small text-muted-soft mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        Times are in {{ $timezone }}. Backups older than {{ $retention }} days are deleted by themselves. Automatic backups need the server's scheduler running — a cron entry for <code>php artisan schedule:run</code> every minute.
                    </p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-box-seam"></i> What a backup holds</div>
                <div class="card-body small">
                    <ul class="list-unstyled backup-holds mb-0">
                        <li><i class="bi bi-check2 text-success"></i> Every RFQ, part, comment, return and timed step</li>
                        <li><i class="bi bi-check2 text-success"></i> People, roles, permissions and every setting</li>
                        <li><i class="bi bi-check2 text-success"></i> Attendance, working hours, messages and alerts</li>
                        <li><i class="bi bi-check2 text-success"></i> Profile photos and comment files — if included</li>
                        <li class="text-muted-soft"><i class="bi bi-dash"></i> Not who's signed in, the cache or queued jobs</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Back up now. --}}
    <div class="modal fade" id="backupNowModal" tabindex="-1" aria-labelledby="backupNowLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('admin.backups.store') }}" class="modal-content js-backup-busy">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="backupNowLabel">Back up now</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted-soft">A backup of every table of the app's data, as it is right now. It's kept {{ $retention }} days.</p>
                    <div class="form-check form-switch">
                        <input type="hidden" name="include_files" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="include_files" id="backup-now-files" value="1" @checked($schedule['include_files'])>
                        <label class="form-check-label" for="backup-now-files">Include uploaded photos and files</label>
                    </div>
                    <div class="form-text">Leave them out for a smaller, quicker backup of just the data.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Back up now</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Upload a backup downloaded before. --}}
    <div class="modal fade" id="backupUploadModal" tabindex="-1" aria-labelledby="backupUploadLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('admin.backups.upload') }}" enctype="multipart/form-data" class="modal-content js-backup-busy" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="backupUploadLabel">Upload a backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted-soft">A backup .zip downloaded from here before. It's added to the list — nothing changes until you restore it.</p>
                    <input type="file" name="backup" accept=".zip" class="form-control @error('backup', 'upload') is-invalid @enderror" required>
                    @error('backup', 'upload')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Restore — one popup, pointed at the backup picked by the script below. --}}
    <div class="modal fade" id="backupRestoreModal" tabindex="-1" aria-labelledby="backupRestoreLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ $restoreFailed && old('backup_name') ? route('admin.backups.restore', old('backup_name')) : '#' }}" class="modal-content js-backup-busy" id="backupRestoreForm" novalidate>
                @csrf
                <input type="hidden" name="backup_name" value="{{ $restoreFailed ? old('backup_name') : '' }}">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="backupRestoreLabel">Restore this backup?</h5>
                        <div class="small text-muted-soft" id="backup-restore-made"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Everything in the app goes back to how it was then — every RFQ, person and setting<span id="backup-restore-files">, and the uploaded photos and files</span>. What's been done since is replaced. A backup of how things are now is made first, so you can undo this.
                    </div>
                    <div class="small text-warning-emphasis mb-3 d-none" id="backup-restore-updates">
                        <i class="bi bi-info-circle"></i> This backup is from before <span></span> app updates — anything those set up (like new permissions) may need setting up again.
                    </div>

                    <div class="mb-3">
                        <label for="backup-restore-confirmation" class="form-label">Type <code>{{ \App\Http\Controllers\Admin\BackupController::RESTORE_PHRASE }}</code> to confirm</label>
                        <input type="text" name="confirmation" id="backup-restore-confirmation" autocomplete="off" spellcheck="false"
                               class="form-control @error('confirmation', 'restore') is-invalid @enderror" value="{{ $restoreFailed ? old('confirmation') : '' }}" required>
                        @error('confirmation', 'restore')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div>
                        <label for="backup-restore-password" class="form-label">Your password</label>
                        <input type="password" name="password" id="backup-restore-password" autocomplete="current-password"
                               class="form-control @error('password', 'restore') is-invalid @enderror" required>
                        @error('password', 'restore')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var restoreModal = document.getElementById('backupRestoreModal');
            var form = document.getElementById('backupRestoreForm');

            // Point the restore popup at the backup picked.
            function pointAt(button) {
                form.action = button.dataset.action;
                form.elements.backup_name.value = button.dataset.name;
                document.getElementById('backup-restore-made').textContent = 'Made ' + button.dataset.made + ' · ' + button.dataset.name;
                document.getElementById('backup-restore-files').classList.toggle('d-none', button.dataset.files !== '1');
                var updates = document.getElementById('backup-restore-updates');
                updates.classList.toggle('d-none', button.dataset.updates === '0');
                updates.querySelector('span').textContent = button.dataset.updates;
            }

            restoreModal.addEventListener('show.bs.modal', function (event) {
                if (event.relatedTarget) {
                    form.elements.confirmation.value = '';
                    form.elements.confirmation.classList.remove('is-invalid');
                    form.elements.password.classList.remove('is-invalid');
                    pointAt(event.relatedTarget);
                }
            });

            // Making or restoring a backup takes a moment: one click, and say so.
            document.querySelectorAll('.js-backup-busy').forEach(function (busyForm) {
                busyForm.addEventListener('submit', function () {
                    var submit = busyForm.querySelector('[type="submit"]');
                    submit.disabled = true;
                    submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Working…';
                });
            });

            // A failed restore or upload opens its popup again, as it was.
            @if ($restoreFailed && old('backup_name'))
                var failed = document.querySelector('.js-restore-backup[data-name="' + @json(old('backup_name')) + '"]');
                if (failed) {
                    pointAt(failed);
                    bootstrap.Modal.getOrCreateInstance(restoreModal).show();
                }
            @endif
            @if ($uploadFailed)
                bootstrap.Modal.getOrCreateInstance(document.getElementById('backupUploadModal')).show();
            @endif
        });
    </script>
@endpush
