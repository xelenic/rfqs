<?php

namespace App;

use App\Models\RfqCommentAttachment;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups of the application's data, for Admin (Admin\BackupController) and
 * the scheduler (backup:scheduled): one zip each in storage/app/private/
 * backups, holding every table of this app's own database as JSON lines —
 * not the cache, sessions or queued jobs, which are just the app running —
 * and, when asked, the uploaded files (profile photos, comment photos and
 * files). Made without any database tool, so they work on MySQL and SQLite
 * alike, and restored into the schema the app has now. Kept RETENTION_DAYS,
 * then pruned. A restore takes a backup of how things are first.
 */
final class BackupManager
{
    /**
     * What the manifest says a backup is — anything else isn't ours.
     */
    public const FORMAT = 'rfqms-backup';

    public const VERSION = 1;

    public const DISK = 'local';

    public const DIRECTORY = 'backups';

    /**
     * How long a backup is kept — from when it was made or uploaded.
     */
    public const RETENTION_DAYS = 7;

    /**
     * What made a backup.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'manual' => 'Manual',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'safety' => 'Before restore',
        'uploaded' => 'Uploaded',
    ];

    /**
     * The tables that are the app running rather than its data — never
     * backed up, nor touched by a restore.
     *
     * @var list<string>
     */
    public const SKIPPED_TABLES = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /**
     * A backup's file name: nothing that could reach outside the folder.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9_-]+\.zip$/';

    private const INSERT_CHUNK = 500;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * Where the uploaded files are: [disk, folder] each.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function fileDirectories(): array
    {
        return [
            [User::AVATAR_DISK, 'avatars'],
            [RfqCommentAttachment::DISK, 'rfq-attachments'],
        ];
    }

    /**
     * Every table in this app's own database — not the others a database
     * server shares with it.
     *
     * @return list<string>
     */
    public static function databaseTables(): array
    {
        $connection = DB::connection();
        $schema = $connection->getDriverName() === 'sqlite' ? null : $connection->getDatabaseName();

        return collect(Schema::getTableListing(schema: $schema, schemaQualified: false))
            ->reject(fn (string $table) => str_starts_with($table, 'sqlite_'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The tables a backup holds: all of them but SKIPPED_TABLES.
     *
     * @return list<string>
     */
    public static function backedUpTables(): array
    {
        return array_values(array_diff(self::databaseTables(), self::SKIPPED_TABLES));
    }

    /**
     * Makes a backup now — $type is what made it (TYPES) — with the uploaded
     * files too if $includeFiles. Written beside the others under a
     * temporary name first, so a half-made one is never listed.
     *
     * @return array<string, mixed> describe()
     */
    public static function create(string $type, bool $includeFiles, ?User $by = null): array
    {
        @set_time_limit(0);

        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIRECTORY);
        $now = CarbonImmutable::now(Setting::timezone());
        $name = self::freeName($now->format('Y-m-d_His').'_'.$type);
        $target = $disk->path(self::DIRECTORY.'/'.$name);
        $partial = $target.'.part';

        $zip = new ZipArchive;

        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Couldn't create the backup file — check that storage/app/private is writable.");
        }

        $temporary = [];

        try {
            $counts = [];

            foreach (self::backedUpTables() as $table) {
                $file = tempnam(sys_get_temp_dir(), 'rfqms-backup-');
                $temporary[] = $file;
                $handle = fopen($file, 'wb');
                $rows = 0;

                foreach (DB::table($table)->cursor() as $row) {
                    fwrite($handle, json_encode((array) $row, self::JSON_FLAGS)."\n");
                    $rows++;
                }

                fclose($handle);
                $zip->addFile($file, "database/{$table}.jsonl");
                $counts[$table] = $rows;
            }

            $files = 0;

            if ($includeFiles) {
                foreach (self::fileDirectories() as [$fileDisk, $directory]) {
                    $storage = Storage::disk($fileDisk);

                    foreach ($storage->allFiles($directory) as $path) {
                        $zip->addFile($storage->path($path), "files/{$fileDisk}/{$path}");
                        $files++;
                    }
                }
            }

            $zip->addFromString('manifest.json', json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'app' => config('app.name'),
                'type' => $type,
                'created_at' => $now->toIso8601String(),
                'created_by' => $by ? ['id' => $by->id, 'name' => $by->name] : null,
                'driver' => DB::connection()->getDriverName(),
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => $counts,
                'includes_files' => $includeFiles,
                'files' => $files,
            ], self::JSON_FLAGS | JSON_PRETTY_PRINT));

            if (! $zip->close()) {
                throw new RuntimeException("Couldn't finish writing the backup file.");
            }
        } catch (Throwable $exception) {
            @$zip->close();
            @unlink($partial);

            throw $exception;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        rename($partial, $target);

        return self::describe($name, self::currentMigrations());
    }

    /**
     * Every backup there is, latest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(): Collection
    {
        $migrations = self::currentMigrations();

        return collect(Storage::disk(self::DISK)->files(self::DIRECTORY))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match(self::NAME_PATTERN, $name) === 1)
            ->map(fn (string $name) => self::describe($name, $migrations))
            ->sortByDesc(fn (array $backup) => $backup['stored_at']->getTimestamp())
            ->values();
    }

    /**
     * One backup by its file name — null if there's no such backup.
     *
     * @return array<string, mixed>|null describe()
     */
    public static function find(string $name): ?array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || ! Storage::disk(self::DISK)->exists(self::DIRECTORY.'/'.$name)) {
            return null;
        }

        return self::describe($name, self::currentMigrations());
    }

    /**
     * Where a backup's file is, for download.
     */
    public static function path(string $name): string
    {
        return Storage::disk(self::DISK)->path(self::DIRECTORY.'/'.$name);
    }

    /**
     * Deletes a backup — false if there was no such backup.
     */
    public static function delete(string $name): bool
    {
        return self::find($name) !== null && Storage::disk(self::DISK)->delete(self::DIRECTORY.'/'.$name);
    }

    /**
     * Deletes every backup older than RETENTION_DAYS — by when it reached
     * this server. How many went.
     */
    public static function prune(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays(self::RETENTION_DAYS);

        return self::all()
            ->filter(fn (array $backup) => $backup['stored_at']->lt($cutoff))
            ->each(fn (array $backup) => self::delete($backup['name']))
            ->count();
    }

    /**
     * Keeps a backup made here (and downloaded) that's uploaded again — as
     * one of the list, to restore from.
     *
     * @return array<string, mixed> describe()
     */
    public static function storeUpload(UploadedFile $file): array
    {
        if (self::manifestOf((string) $file->getRealPath()) === null) {
            throw new RuntimeException("That file isn't a backup made by this app.");
        }

        Storage::disk(self::DISK)->makeDirectory(self::DIRECTORY);
        $name = self::freeName(CarbonImmutable::now(Setting::timezone())->format('Y-m-d_His').'_uploaded');
        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, $name);

        return self::describe($name, self::currentMigrations());
    }

    /**
     * Puts the data back as it was in the backup: every table emptied and
     * filled again from it, in one transaction — and the uploaded files too,
     * if it has them. A backup of how things are now is made first (type
     * "safety"), so the restore itself can be undone. Refused for a backup
     * made by a newer version of the app, whose data may not fit.
     *
     * @return array{tables: int, rows: int, files: int, safety: string}
     */
    public static function restore(string $name, ?User $by = null): array
    {
        @set_time_limit(0);

        $backup = self::find($name) ?? throw new RuntimeException('That backup is no longer there.');

        if (! $backup['valid']) {
            throw new RuntimeException("That file isn't a backup made by this app.");
        }

        if ($backup['compatibility'] === 'newer') {
            throw new RuntimeException('That backup was made by a newer version of the app — update this one first.');
        }

        $zip = new ZipArchive;

        if ($zip->open($backup['path'], ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Couldn't open the backup file.");
        }

        try {
            $tables = array_keys($backup['manifest']['tables']);

            foreach ($tables as $table) {
                if ($zip->locateName("database/{$table}.jsonl") === false) {
                    throw new RuntimeException("The backup is incomplete — {$table} is missing from it.");
                }
            }

            $safety = self::create('safety', (bool) $backup['includes_files'], $by);
            $current = array_values(array_diff(self::backedUpTables(), ['migrations']));
            $restorable = array_values(array_intersect($tables, $current));
            $rows = 0;

            Schema::disableForeignKeyConstraints();

            try {
                DB::transaction(function () use ($zip, $current, $restorable, &$rows) {
                    // SQLite can't turn its foreign keys off inside a
                    // transaction — it checks them at the end instead.
                    if (DB::connection()->getDriverName() === 'sqlite') {
                        DB::statement('PRAGMA defer_foreign_keys = ON');
                    }

                    foreach ($current as $table) {
                        DB::table($table)->delete();
                    }

                    foreach ($restorable as $table) {
                        $rows += self::restoreTable($zip, $table);
                    }
                });
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            $files = $backup['includes_files'] ? self::restoreFiles($zip) : 0;
        } finally {
            $zip->close();
        }

        // Cached settings, permissions and the like were the old data's.
        Cache::flush();
        LiveVersion::bump();

        return ['tables' => count($restorable), 'rows' => $rows, 'files' => $files, 'safety' => $safety['name']];
    }

    /**
     * What's known of a backup: its file, what made it and when, what's in
     * it, and whether it fits the app as it is now ($migrations) — "same",
     * "older" (made before updates since: it restores, but what those
     * updates added may need setting up again), or "newer" (it won't).
     *
     * @param  list<string>  $migrations
     * @return array<string, mixed>
     */
    private static function describe(string $name, array $migrations): array
    {
        $disk = Storage::disk(self::DISK);
        $path = self::DIRECTORY.'/'.$name;
        $manifest = self::manifestOf($disk->path($path));
        $storedAt = CarbonImmutable::createFromTimestamp($disk->lastModified($path), Setting::timezone());
        $theirs = $manifest['migrations'] ?? [];

        return [
            'name' => $name,
            'path' => $disk->path($path),
            'size' => (int) $disk->size($path),
            'stored_at' => $storedAt,
            'created_at' => isset($manifest['created_at']) ? CarbonImmutable::parse($manifest['created_at'])->setTimezone(Setting::timezone()) : $storedAt,
            'type' => str_contains($name, '_uploaded') ? 'uploaded' : ($manifest['type'] ?? 'uploaded'),
            'created_by' => $manifest['created_by']['name'] ?? null,
            'includes_files' => (bool) ($manifest['includes_files'] ?? false),
            'files' => (int) ($manifest['files'] ?? 0),
            'tables' => count($manifest['tables'] ?? []),
            'rows' => (int) array_sum($manifest['tables'] ?? []),
            'valid' => $manifest !== null,
            'compatibility' => match (true) {
                $manifest === null => null,
                array_diff($theirs, $migrations) !== [] => 'newer',
                array_diff($migrations, $theirs) !== [] => 'older',
                default => 'same',
            },
            'updates_since' => count(array_diff($migrations, $theirs)),
            'manifest' => $manifest,
        ];
    }

    /**
     * A zip's manifest, if it's one of our backups — null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private static function manifestOf(string $path): ?array
    {
        $zip = new ZipArchive;

        if ($path === '' || $zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $json = $zip->getFromName('manifest.json');
        $zip->close();
        $manifest = is_string($json) ? json_decode($json, true) : null;

        return is_array($manifest)
            && ($manifest['format'] ?? null) === self::FORMAT
            && ($manifest['version'] ?? null) === self::VERSION
            && is_array($manifest['tables'] ?? null)
                ? $manifest
                : null;
    }

    /**
     * Fills $table again from the backup's rows — only the columns it still
     * has. How many rows.
     */
    private static function restoreTable(ZipArchive $zip, string $table): int
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $stream = $zip->getStream("database/{$table}.jsonl");
        $batch = [];
        $rows = 0;

        while (($line = fgets($stream)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $batch[] = array_intersect_key(json_decode($line, true, 512, JSON_THROW_ON_ERROR), $columns);
            $rows++;

            if (count($batch) === self::INSERT_CHUNK) {
                DB::table($table)->insert($batch);
                $batch = [];
            }
        }

        fclose($stream);

        if ($batch !== []) {
            DB::table($table)->insert($batch);
        }

        return $rows;
    }

    /**
     * Puts the uploaded files back as they were: each folder emptied and
     * refilled from the backup — only what belongs in those folders, and
     * nothing that would reach outside them. How many files.
     */
    private static function restoreFiles(ZipArchive $zip): int
    {
        foreach (self::fileDirectories() as [$fileDisk, $directory]) {
            Storage::disk($fileDisk)->deleteDirectory($directory);
        }

        $files = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);

            if (str_contains($entry, '..') || str_contains($entry, '\\') || str_ends_with($entry, '/')) {
                continue;
            }

            foreach (self::fileDirectories() as [$fileDisk, $directory]) {
                if (str_starts_with($entry, "files/{$fileDisk}/{$directory}/")) {
                    $stream = $zip->getStream($entry);
                    Storage::disk($fileDisk)->writeStream(substr($entry, strlen("files/{$fileDisk}/")), $stream);
                    fclose($stream);
                    $files++;

                    break;
                }
            }
        }

        return $files;
    }

    /**
     * $base.zip, or $base-2.zip and on if that's taken.
     */
    private static function freeName(string $base): string
    {
        $disk = Storage::disk(self::DISK);
        $name = "{$base}.zip";

        for ($copy = 2; $disk->exists(self::DIRECTORY.'/'.$name); $copy++) {
            $name = "{$base}-{$copy}.zip";
        }

        return $name;
    }

    /**
     * The migrations this app's database has run — what a backup's are
     * compared with.
     *
     * @return list<string>
     */
    private static function currentMigrations(): array
    {
        return DB::table('migrations')->pluck('migration')->all();
    }
}
