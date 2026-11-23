<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * HBDs Operations was renamed "HBDs Operations (Section In-Charge)" before
     * add_slug_to_roles_table ran, which didn't know that name and gave it the
     * slug of it — so the code, which knows the role as 'hbds-operations',
     * stopped seeing its holders as HBDs Operations. Gives it back its slug.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->where('slug', 'hbds-operations-section-in-charge')->get(['id', 'guard_name']) as $role) {
            $taken = DB::table('roles')
                ->where('guard_name', $role->guard_name)
                ->where('slug', 'hbds-operations')
                ->exists();

            if (! $taken) {
                DB::table('roles')->where('id', $role->id)->update(['slug' => 'hbds-operations']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
