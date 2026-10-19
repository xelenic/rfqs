<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * HR Manager is called HR Executive now, and the code looks the role up
     * by that name. Renames it, keeping its people, permissions and hours.
     * Where HR Executive was already made (renamed on the Roles page, say),
     * nothing's needed — or, if both are there, HR Manager's people and
     * permissions join HR Executive and HR Manager goes.
     */
    public function up(): void
    {
        $old = Role::query()->where('name', 'HR Manager')->first();

        if ($old !== null) {
            $new = Role::query()->where('name', 'HR Executive')->where('guard_name', $old->guard_name)->first();

            if ($new === null) {
                $old->name = 'HR Executive';
                $old->save();
            } else {
                $new->givePermissionTo($old->permissions);

                DB::table('model_has_roles')->where('role_id', $old->id)->get()->each(fn (object $holder) => DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $new->id,
                    'model_type' => $holder->model_type,
                    'model_id' => $holder->model_id,
                ]));

                $old->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Back to HR Manager, unless there's one already.
     */
    public function down(): void
    {
        if (Role::query()->where('name', 'HR Manager')->doesntExist()) {
            Role::query()->where('name', 'HR Executive')->first()?->forceFill(['name' => 'HR Manager'])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
