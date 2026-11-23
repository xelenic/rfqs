<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Each feature a role had by name until now, and the roles (by slug)
     * that had it — as App\Permissions listed them on 2026-11-24.
     *
     * @var array<string, array<int, string>>
     */
    private const FEATURES = [
        'rfqs.list-all' => ['admin', 'general-manager', 'hbds-operations', 'operation-assistant-trainee'],
        'rfqs.change-status' => ['admin', 'hbds-operations', 'operation-assistant-trainee'],
        'rfqs.change-priority' => ['admin', 'business-development', 'hbds-operations'],
        'rfqs.return-to-senior-ops' => ['admin'],
        'rfqs.critical-monitor' => ['admin', 'hbds-operations', 'operation-assistant-trainee'],
        'rfqs.clear-data' => ['admin'],
        'reports.time-spent' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'hr-executive'],
        'reports.performance' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'hr-executive'],
        'reports.sourcing-figures' => ['admin', 'hbds-operations', 'operation-assistant-trainee'],
        'attendance.keep' => ['admin', 'hbds-operations', 'operation-assistant-trainee'],
        'attendance.approve' => ['admin', 'hr-executive'],
        'working-hours.manage' => ['admin', 'hr-executive'],
        'users.change-details' => ['admin', 'hr-executive'],
        'users.set-password' => ['admin'],
        'users.deactivate' => ['admin'],
        'users.impersonate' => ['admin'],
        'users.sourcing-setup' => ['admin'],
        'settings.manage' => ['admin'],
        'job-categories.manage' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'gms-operations'],
        'message-groups.manage' => ['admin'],
        'logs.view' => ['admin'],
        'backups.manage' => ['admin'],
    ];

    /**
     * Since 2026-11-24, at the user's request, what a role could do by its
     * name is a permission, switched on or off for it on the Roles page.
     * Makes each one, and gives it to the roles that had it — so nobody's
     * access changes until an Admin changes it.
     */
    public function up(): void
    {
        $guards = DB::table('roles')->distinct()->pluck('guard_name')->push(config('auth.defaults.guard'))->unique();

        foreach ($guards as $guard) {
            foreach (self::FEATURES as $name => $slugs) {
                $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id')
                    ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);

                $roleIds = DB::table('roles')->where('guard_name', $guard)->whereIn('slug', $slugs)->pluck('id');
                $given = DB::table('role_has_permissions')->where('permission_id', $permissionId)->pluck('role_id');

                DB::table('role_has_permissions')->insert(
                    $roleIds->diff($given)->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->values()->all()
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Takes the features away again: what a role could do goes back to
     * going by its name.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::FEATURES))->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
