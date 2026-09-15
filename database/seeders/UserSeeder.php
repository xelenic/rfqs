<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Seed a handful of sample logins per role (besides the primary Admin
     * account created in RolePermissionSeeder), so every role has more than
     * one real user to sign in and test with — multi-assignment features
     * like RFQ Sourcing assignment especially need more than one person to
     * pick from. All share the password "password".
     *
     * Emails for the first person on each role are kept stable across runs
     * (existing test data/URLs elsewhere in the app rely on them);
     * additional people just get a numbered suffix.
     */
    public function run(): void
    {
        $users = [
            'Admin' => [
                ['name' => 'Avery Thompson', 'email' => 'admin2@rfqms.test'],
            ],
            'Manager' => [
                ['name' => 'Morgan Blake', 'email' => 'manager@rfqms.test'],
                ['name' => 'Jamie Whitfield', 'email' => 'manager2@rfqms.test'],
            ],
            'Business Development' => [
                ['name' => 'Bailey Nguyen', 'email' => 'business.development@rfqms.test'],
                ['name' => 'Taylor Osei', 'email' => 'business.development2@rfqms.test'],
            ],
            'Sourcing' => [
                ['name' => 'Sam Rivera', 'email' => 'sourcing@rfqms.test'],
                ['name' => 'Riley Chen', 'email' => 'sourcing2@rfqms.test'],
                ['name' => 'Priya Desai', 'email' => 'sourcing3@rfqms.test'],
            ],
            'Senior Operations' => [
                ['name' => 'Casey Patel', 'email' => 'operations@rfqms.test'],
                ['name' => 'Drew Nakamura', 'email' => 'operations2@rfqms.test'],
            ],
            'Head of Business Development' => [
                ['name' => 'Jordan Lee', 'email' => 'head.of.business.development@rfqms.test'],
                ['name' => 'Reese Anderson', 'email' => 'head.of.business.development2@rfqms.test'],
            ],
            'Data Entry' => [
                ['name' => 'Morgan Ito', 'email' => 'data.entry@rfqms.test'],
                ['name' => 'Casey Brooks', 'email' => 'data.entry2@rfqms.test'],
            ],
            'GM Assistant' => [
                ['name' => 'Harper Collins', 'email' => 'gm.assistant@rfqms.test'],
                ['name' => 'Devon Marsh', 'email' => 'gm.assistant2@rfqms.test'],
            ],
            'General Manager' => [
                ['name' => 'Quinn Alderman', 'email' => 'general.manager@rfqms.test'],
                ['name' => 'Frankie Sutton', 'email' => 'general.manager2@rfqms.test'],
            ],
        ];

        foreach ($users as $roleName => $people) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            foreach ($people as $person) {
                $user = User::firstOrCreate(
                    ['email' => $person['email']],
                    [
                        'name' => $person['name'],
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                    ]
                );

                $user->syncRoles([$role]);
            }
        }
    }
}
