<?php

namespace App;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The data's version, for live updates: a token that changes whenever
 * something the pages show is written — an RFQ, an assignment, a comment, a
 * message. The signed-in pages ask for it every few seconds (see
 * Admin\LivePulseController and public/js/live.js) and refresh themselves
 * when it's not the one they were rendered with.
 *
 * It's bumped from the queries themselves rather than from model events:
 * a part's progress lives on the rfq_user pivot, which is updated through
 * paths that fire no events (updateExistingPivot() with a where, DB::table()
 * updates), and it must not depend on remembering to call anything.
 */
final class LiveVersion
{
    /**
     * The tables the pages show, whose writes make the version change.
     * Users, roles and permissions are deliberately not live.
     *
     * @var list<string>
     */
    public const WATCHED_TABLES = ['rfqs', 'rfq_user', 'rfq_comments', 'private_messages', 'job_categories'];

    /**
     * Every other table — framework plumbing, access control and settings. A new table
     * has to be put in one list or the other (tests/Feature/LiveUpdatesTest.php
     * fails until it is), so a page's data can't quietly be left out of live
     * updates.
     *
     * @var list<string>
     */
    public const IGNORED_TABLES = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions', 'settings',
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    ];

    private const CACHE_KEY = 'live-version';

    /**
     * The current version — opaque; only whether it differs matters.
     */
    public static function current(): string
    {
        return (string) Cache::get(self::CACHE_KEY, '0');
    }

    /**
     * Moves the version on. A random token, not a counter, so it can't repeat
     * after the cache has been cleared.
     */
    public static function bump(): void
    {
        try {
            Cache::forever(self::CACHE_KEY, Str::random(12));
        } catch (Throwable $exception) {
            // Live updates going stale mustn't fail the write that caused them.
            report($exception);
        }
    }

    /**
     * Starts watching the queries for writes to the tables above. Each bumps
     * the version once its transaction has committed — bumped any earlier, a
     * page could be re-rendered from the old data and then believe it's up to
     * date.
     */
    public static function watch(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (self::writesWatchedTable($query->sql)) {
                $query->connection->afterCommit(self::bump(...));
            }
        });
    }

    /**
     * Whether a statement inserts into, updates or deletes from a watched table.
     */
    public static function writesWatchedTable(string $sql): bool
    {
        return preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|update(?:\s+or\s+\w+)?|delete\s+from)\s+[`"\[]?(\w+)/i', $sql, $matches) === 1
            && in_array($matches[1], self::WATCHED_TABLES, true);
    }
}
