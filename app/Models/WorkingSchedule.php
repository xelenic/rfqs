<?php

namespace App\Models;

use App\LiveVersion;
use Database\Factories\WorkingScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * A role's own working week, or one person's, in place of the company's
 * (Setting::workingHours()) — the same shape: each day worked or not, its
 * hours and its lunch. Someone's time counts in their own week if they have
 * one, else in their role's, else in the company's (Setting::weekOn()); their
 * extra hours (ExtraWorkingHour) still count on top. One row per version:
 * each counts from starts_on until a later one starts — or, once taken away,
 * until ended_on, the first day it no longer counts — so a change counts from
 * the day it's made and the time already counted stays counted. Kept by an
 * Admin on Settings → Role Hours and on a person's page. Read through
 * cached(), kept until a version, a role or who holds one changes (watch()).
 */
class WorkingSchedule extends Model
{
    /** @use HasFactory<WorkingScheduleFactory> */
    use HasFactory;

    private const CACHE_KEY = 'working_schedules';

    /**
     * The tables whose writes change whose hours are whose: the versions,
     * the roles, and who holds them.
     *
     * @var array<int, string>
     */
    private const CACHED_FROM_TABLES = ['working_schedules', 'roles', 'model_has_roles'];

    protected $fillable = [
        'role_id',
        'user_id',
        'week',
        'starts_on',
        'ended_on',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week' => 'array',
            'starts_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCached());
        static::deleted(fn () => static::forgetCached());
    }

    /**
     * Forgets the cached versions whenever a write changes them, whose role
     * is whose, or a role itself — however it's made: Spatie's syncRoles()
     * detaches straight from the table, and deleting a role or a person takes
     * their versions with it in the database alone. Again once the write has
     * committed, so a page rendered meanwhile can't keep the old ones.
     */
    public static function watch(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (in_array(LiveVersion::tableWrittenBy($query->sql), self::CACHED_FROM_TABLES, true)) {
                static::forgetCached();
                $query->connection->afterCommit(static::forgetCached(...));
            }
        });
    }

    public static function forgetCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Every version — each role's by role id, each person's by user id,
     * oldest first, its week whole (Setting::weekFrom()), its days as
     * "Y-m-d" — and, for the roles with any, who holds them: user id => role
     * ids, in roleOrder().
     *
     * @return array{roles: array<int, array<int, array{week: array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>, starts_on: string, ended_on: ?string}>>, people: array<int, array<int, array{week: array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>, starts_on: string, ended_on: ?string}>>, holders: array<int, array<int, int>>}
     */
    public static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = static::query()->orderBy('starts_on')->orderBy('id')->get();
            $versions = fn (Collection $group) => $group->map(fn (self $version) => [
                'week' => Setting::weekFrom($version->week),
                'starts_on' => $version->starts_on->toDateString(),
                'ended_on' => $version->ended_on?->toDateString(),
            ])->values()->all();

            $roles = $rows->whereNotNull('role_id')->groupBy('role_id')->map($versions)->all();
            $order = static::roleOrder()->pluck('id')->flip();

            $holders = $roles === [] ? [] : DB::table(config('permission.table_names.model_has_roles'))
                ->whereIn('role_id', array_keys($roles))
                ->where('model_type', (new User)->getMorphClass())
                ->get(['model_id', 'role_id'])
                ->groupBy('model_id')
                ->map(fn (Collection $held) => $held->pluck('role_id')->map(fn ($id) => (int) $id)
                    ->sortBy(fn (int $id) => $order[$id] ?? PHP_INT_MAX)->values()->all())
                ->all();

            return [
                'roles' => $roles,
                'people' => $rows->whereNotNull('user_id')->groupBy('user_id')->map($versions)->all(),
                'holders' => $holders,
            ];
        });
    }

    /**
     * Whether any role or anyone has their own hours, on any day — when
     * nobody does, everyone's are the company's and there's nothing to look up.
     */
    public static function anyExist(): bool
    {
        $cached = static::cached();

        return $cached['roles'] !== [] || $cached['people'] !== [];
    }

    /**
     * The week $userId works on $date ("Y-m-d", in the working hours' time
     * zone): their own, else that of the first of their roles (roleOrder())
     * with its own, as it was that day — with where it's from. Null: the
     * company's.
     *
     * @return array{week: array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>, source: 'person'|'role', role_id: ?int}|null
     */
    public static function countingOn(?int $userId, string $date): ?array
    {
        if ($userId === null) {
            return null;
        }

        $cached = static::cached();

        if (($own = static::versionOn($cached['people'][$userId] ?? [], $date)) !== null) {
            return ['week' => $own['week'], 'source' => 'person', 'role_id' => null];
        }

        foreach ($cached['holders'][$userId] ?? [] as $roleId) {
            if (($role = static::versionOn($cached['roles'][$roleId] ?? [], $date)) !== null) {
                return ['week' => $role['week'], 'source' => 'role', 'role_id' => $roleId];
            }
        }

        return null;
    }

    /**
     * Of one role's versions or one person's (oldest first), the one counting
     * on $date: the latest to have started by then — none if that one had
     * been taken away by then.
     *
     * @param  array<int, array{week: array<string, mixed>, starts_on: string, ended_on: ?string}>  $versions
     * @return array{week: array<string, mixed>, starts_on: string, ended_on: ?string}|null
     */
    private static function versionOn(array $versions, string $date): ?array
    {
        $counting = null;

        foreach ($versions as $version) {
            if ($version['starts_on'] > $date) {
                break;
            }

            $counting = $version;
        }

        return $counting !== null && ($counting['ended_on'] === null || $date < $counting['ended_on']) ? $counting : null;
    }

    /**
     * Whoever works hours other than the company's from today on — their own,
     * or their role's — by user id.
     *
     * @return array<int, int>
     */
    public static function peopleWithOwnHours(): array
    {
        $cached = static::cached();
        $today = Setting::today();
        $stillCounting = fn (array $versions) => collect($versions)->contains(fn (array $version) => $version['ended_on'] === null || $version['ended_on'] > $today);

        $roles = collect($cached['roles'])->filter($stillCounting)->keys()->all();

        return collect($cached['people'])->filter($stillCounting)->keys()
            ->merge(collect($cached['holders'])->filter(fn (array $held) => array_intersect($held, $roles) !== [])->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every role, in the order their hours come first for someone holding
     * more than one with their own — and the order Settings → Role Hours
     * lists them: the workflow's (Rfq::WORKFLOW_ROLES), then the rest by name.
     *
     * @return Collection<int, Role>
     */
    public static function roleOrder(): Collection
    {
        $workflow = array_flip(Rfq::WORKFLOW_ROLES);

        return Role::query()->orderBy('name')->get()
            ->sortBy(fn (Role $role) => [$workflow[$role->name] ?? PHP_INT_MAX, $role->name])
            ->values();
    }

    /**
     * $owner's version counting today — none if they work someone else's.
     */
    public static function currentFor(Role|User $owner): ?self
    {
        $today = Setting::today();
        $latest = static::query()->ownedBy($owner)
            ->whereDate('starts_on', '<=', $today)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && ($latest->ended_on === null || $latest->ended_on->toDateString() > $today) ? $latest : null;
    }

    /**
     * Gives $owner $week from today on: today's version changed, if they have
     * one that started today, else a new one from today. Days before keep the
     * hours they had.
     *
     * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
     */
    public static function putFor(Role|User $owner, array $week, User $by): self
    {
        $current = static::currentFor($owner);

        if ($current !== null && $current->starts_on->toDateString() === Setting::today()) {
            $current->update(['week' => $week, 'created_by' => $by->id]);

            return $current;
        }

        return static::query()->create([
            $owner instanceof Role ? 'role_id' : 'user_id' => $owner->getKey(),
            'week' => $week,
            'starts_on' => Setting::today(),
            'created_by' => $by->id,
        ]);
    }

    /**
     * Takes $owner's own hours away from today on — they go back to their
     * role's, or the company's. Days before keep the hours they had. False if
     * they had none.
     */
    public static function endFor(Role|User $owner): bool
    {
        $current = static::currentFor($owner);

        return $current !== null && $current->update(['ended_on' => Setting::today()]);
    }

    /**
     * Versions for $owner — a role, or a person.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, Role|User $owner): void
    {
        $query->where($owner instanceof Role ? 'role_id' : 'user_id', $owner->getKey());
    }

    /**
     * The role whose hours these are, if they're a role's.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The person whose hours these are, if they're one person's.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who set them.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
