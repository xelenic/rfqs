<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * HR Manager adds people: they see the Users page and create accounts
     * (never Admin ones — see Admin\UserController::store()). Added to the
     * role as it stands, nothing else about it changed; a fresh install gets
     * it from BusinessRoleSeeder.
     */
    public function up(): void
    {
        $role = Role::query()->where('name', 'HR Manager')->first();

        if ($role === null) {
            return;
        }

        $role->givePermissionTo(collect(['users.view', 'users.create'])->map(fn (string $name) => Permission::findOrCreate($name)));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::query()->where('name', 'HR Manager')->first()?->revokePermissionTo(['users.view', 'users.create']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
