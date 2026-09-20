<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed default permissions, roles and an admin user.
     */
    public function run(): void
    {
        $resources = ['users', 'roles', 'permissions', 'rfqs'];
        $actions = ['view', 'create', 'edit', 'delete'];

        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$resource}.{$action}");
            }
        }

        $admin = Role::findOrCreate('Admin');
        $admin->description = 'Full access to the admin panel, including users, roles, permissions and RFQs.';
        $admin->save();
        $admin->syncPermissions(Permission::all());

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@rfqms.test'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $adminUser->syncRoles([$admin]);
    }
}
