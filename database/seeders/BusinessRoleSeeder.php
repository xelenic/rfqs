<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class BusinessRoleSeeder extends Seeder
{
    /**
     * Seed the business/functional roles.
     *
     * Each one is given sensible defaults on the RFQ module — the entity
     * they exist to work with — and nothing else. Fine-tune from the admin
     * panel's Roles screen as needed.
     */
    public function run(): void
    {
        // "Operations" is being renamed to "Senior Operations" — renaming
        // the existing row in place (rather than findOrCreate-ing a new
        // one under the new name below) keeps every current Operations
        // user attached, since model_has_roles is keyed by role_id, not
        // name.
        $operations = Role::where('name', 'Operations')->first();
        if ($operations) {
            $operations->name = 'Senior Operations';
            $operations->save();
        }

        $roles = [
            // Only Business Development (and Admin, which always has every
            // permission — see RolePermissionSeeder) can create new RFQs.
            // Every other role works RFQs that already exist.
            'Business Development' => [
                'description' => 'Drives new client acquisition and manages RFQ relationships. Receives the final approved RFQ and closes it out.',
                'permissions' => ['rfqs.view', 'rfqs.create', 'rfqs.edit'],
            ],
            'Senior Operations' => [
                'description' => 'Categorizes incoming RFQs, splits and assigns them to Sourcing, and gives the second-stage review before escalating to Head of Business Development.',
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
            'GM Assistant' => [
                'description' => 'Adds client details and payment terms before forwarding an approved RFQ to the General Manager for final approval.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'General Manager' => [
                'description' => 'Gives final executive approval before an RFQ routes back to Business Development to close.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
        ];

        foreach ($roles as $name => $config) {
            $role = Role::findOrCreate($name);
            $role->description = $config['description'];
            $role->save();
            $role->syncPermissions(Permission::whereIn('name', $config['permissions'])->get());
        }
    }
}
