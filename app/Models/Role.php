<?php

namespace App\Models;

use App\Permissions;
use BackedEnum;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

use function Illuminate\Support\enum_value;

/**
 * A role, which the code knows by its slug: set once, from the name it was
 * made with, and never changed. Since 2026-11-24, at the user's request,
 * renaming a role on the Roles page changes only what it's called — who
 * holds it, its permissions and everything the code gives it stay.
 *
 * The code names a role by the name it was made with ('HBDs Operations'),
 * or by its slug ('hbds-operations'): slugFor() turns either into the slug.
 * Spatie's lookups by name (User::role(), assignRole(), syncRoles(),
 * findByName(), findOrCreate()) go by that slug first.
 */
class Role extends SpatieRole
{
    /**
     * The slug of the role the code calls $role — by the name it was made
     * with, or by its slug.
     */
    public static function slugFor(string $role): string
    {
        return Str::slug($role);
    }

    /**
     * Gives a new role its slug, from its name — numbered when another role
     * of the guard already has it ("sourcing-2"), so a role made with a name
     * another role was made with never takes that role's place. A role that
     * has one keeps it. Also run for Spatie's own Role model (see
     * AppServiceProvider).
     */
    public static function giveSlug(SpatieRole $role): void
    {
        if (filled($role->slug) || ! self::hasSlugs()) {
            return;
        }

        $guard = $role->guard_name ?? Guard::getDefaultName(static::class);
        $base = self::slugFor((string) $role->name) ?: 'role';
        $slug = $base;

        for ($number = 2; static::query()->where('guard_name', $guard)->where('slug', $slug)->exists(); $number++) {
            $slug = "{$base}-{$number}";
        }

        $role->slug = $slug;
    }

    /**
     * Gives a role just made the features it has to begin with, by its slug
     * (App\Permissions::defaultsFor()) — so a role the code knows starts with
     * what it always had, and one of a new slug ("sourcing-2") with none.
     * Switched off on the Roles page, a feature stays off: it's given only
     * here. Also run for Spatie's own Role model (see AppServiceProvider).
     */
    public static function giveDefaultPermissions(SpatieRole $role): void
    {
        $names = Permissions::defaultsFor((string) $role->slug);

        if ($names === []) {
            return;
        }

        $guard = $role->guard_name ?? Guard::getDefaultName(static::class);

        $role->givePermissionTo(array_map(fn (string $name) => Permission::findOrCreate($name, $guard), $names));
    }

    /**
     * Whether roles have slugs yet: an earlier migration making a role runs
     * before add_slug_to_roles_table, which gives it one. Once they have,
     * it isn't asked again.
     */
    private static function hasSlugs(): bool
    {
        static $hasSlugs = false;

        return $hasSlugs = $hasSlugs || Schema::hasColumn((new static)->getTable(), 'slug');
    }

    /**
     * The role with $role's slug (slugFor()) — or null when there isn't one.
     */
    public static function findBySlug(string $role, ?string $guardName = null): ?self
    {
        return static::query()
            ->where('guard_name', $guardName ?? Guard::getDefaultName(static::class))
            ->where('slug', self::slugFor($role))
            ->first();
    }

    /**
     * The role the code calls $name, by its slug — else, for a role made
     * since, the one called $name now.
     */
    public static function findByName(BackedEnum|string $name, ?string $guardName = null): RoleContract
    {
        $name = enum_value($name);

        return self::findBySlug($name, $guardName) ?? parent::findByName($name, $guardName);
    }

    /**
     * The role the code calls $name, by its slug, or one made with that name.
     */
    public static function findOrCreate(BackedEnum|string $name, ?string $guardName = null): RoleContract
    {
        $name = enum_value($name);

        return self::findBySlug($name, $guardName) ?? parent::findOrCreate($name, $guardName);
    }

    protected static function booted(): void
    {
        static::creating(fn (self $role) => self::giveSlug($role));
        static::created(fn (self $role) => self::giveDefaultPermissions($role));
    }
}
