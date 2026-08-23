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
        $roles = [
            // Only Business Development (and Admin, which always has every
            // permission — see RolePermissionSeeder) can create new RFQs.
            // Every other role works RFQs that already exist.
            'Business Development' => [
                'description' => 'Drives new client acquisition and manages RFQ relationships.',
                'permissions' => ['rfqs.view', 'rfqs.create', 'rfqs.edit'],
            ],
            'Operations' => [
                'description' => 'Oversees day-to-day operational workflows and execution.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'Sourcing' => [
                'description' => 'Manages supplier sourcing and procurement for quotations.',
                'permissions' => ['rfqs.view', 'rfqs.edit'],
            ],
            'Head of Business Development' => [
                'description' => 'Leads the business development team and strategic partnerships.',
                'permissions' => ['rfqs.view', 'rfqs.edit', 'rfqs.delete'],
            ],
            'Data Entry' => [
                'description' => 'Processes RFQs handed off by Sourcing into downstream systems.',
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
