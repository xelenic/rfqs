<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class BusinessRoleSeeder extends Seeder
{
    /**
     * Seed the business/functional roles.
     *
     * Each one is given sensible defaults on the RFQ module — the entity
     * they exist to work with — and the features it has to begin with
     * (App\Permissions::defaultsFor()). Fine-tune from the admin panel's
     * Roles screen as needed.
     */
    public function run(): void
    {
        // Each role is found by its slug (App\Models\Role::findOrCreate()),
        // whatever it's called now, so a role renamed on the Roles page keeps
        // its name and its people — and isn't made a second time. Since
        // 2026-11-24 nothing here renames a role: its name is the Admin's.
        $roles = [
            // Only Business Development (and Admin, which always has every
            // permission — see RolePermissionSeeder) can create new RFQs.
            // Every other role works RFQs that already exist.
            'Business Development' => [
                'description' => 'Drives new client acquisition and manages RFQ relationships. Receives the final approved RFQ and closes it out.',
                'permissions' => ['rfqs.view', 'rfqs.create', 'rfqs.edit'],
            ],
            'HBDs Operations' => [
                'description' => 'Categorizes incoming RFQs, splits and assigns them to Sourcing, and gives the second-stage review before escalating to Head of Business Development.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            // Works exactly as HBDs Operations (User::ROLE_STAND_INS).
            'Operation Assistant Trainee' => [
                'description' => 'Trains in HBDs Operations, with the same access: categorizes incoming RFQs, splits and assigns them to Sourcing, and gives the second-stage review.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'Sourcing' => [
                'description' => 'Manages supplier sourcing and procurement for quotations.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'Head of Business Development' => [
                'description' => 'Reviews tasks approved by Senior Operations — approves them on to GM Assistant, or rejects them back to an earlier stage with a reason.',
                'permissions' => ['rfqs.view', 'rfqs.edit', 'rfqs.delete'],
            ],
            'Data Entry' => [
                'description' => 'Processes RFQs handed off by Sourcing into downstream systems.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'GMs Operations' => [
                'description' => 'Submits an RFQ the Head of Business Development approved on to the General Manager for final approval, with a comment if needed.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'General Manager' => [
                'description' => 'Gives final executive approval before an RFQ routes back to Business Development to close.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            // No part in the RFQ workflow: attendance, the reports and
            // people's details are features of theirs (App\Permissions).
            // They add people and change their details — never an Admin's,
            // nor as Admin (see Admin\UserController).
            'HR Executive' => [
                'description' => 'Approves the daily attendance sheet, sees the Time Spent report, and adds people and keeps their details — new people get an email to set their password.',
                'permissions' => ['users.view', 'users.create', 'users.edit'],
            ],
        ];

        foreach ($roles as $name => $config) {
            $role = Role::findOrCreate($name);
            $role->description = $config['description'];
            $role->save();
            $role->syncPermissions(collect([...$config['permissions'], ...Permissions::defaultsFor($role->slug)])
                ->map(fn (string $name) => Permission::findOrCreate($name, $role->guard_name)));
        }

        // The original "Manager" role is retired: the General Manager is the
        // manager here. Whoever held it holds General Manager now — same
        // accounts, same logins — and then it goes.
        $legacyManager = Role::findBySlug('Manager');
        if ($legacyManager) {
            $generalManager = Role::findByName('General Manager');

            foreach ($legacyManager->users()->get() as $user) {
                $user->assignRole($generalManager);
                $user->removeRole($legacyManager);
            }

            $legacyManager->delete();
        }
    }
}
