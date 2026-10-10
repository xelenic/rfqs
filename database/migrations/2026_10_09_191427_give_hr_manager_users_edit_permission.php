<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * HR Manager changes people's details too, alongside Admin — never an
     * Admin's, and never a password (see Admin\UserController::update()).
     * Added to the role as it stands; a fresh install gets it from
     * BusinessRoleSeeder.
     */
    public function up(): void
    {
        Role::query()->where('name', 'HR Manager')->first()?->givePermissionTo(Permission::findOrCreate('users.edit'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::query()->where('name', 'HR Manager')->first()?->revokePermissionTo('users.edit');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
