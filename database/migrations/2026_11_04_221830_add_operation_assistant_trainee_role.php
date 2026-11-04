<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Operation Assistant Trainee works exactly as HBDs Operations
     * (User::ROLE_STAND_INS). Adds the role where HBDs Operations is set up,
     * with its permissions; a fresh install gets it from BusinessRoleSeeder.
     */
    public function up(): void
    {
        $operations = Role::query()->where('name', 'HBDs Operations')->first();

        if ($operations === null || Role::query()->where('name', 'Operation Assistant Trainee')->exists()) {
            return;
        }

        $trainee = Role::create(['name' => 'Operation Assistant Trainee', 'guard_name' => $operations->guard_name]);
        $trainee->forceFill(['description' => 'Trains in HBDs Operations, with the same access: categorizes incoming RFQs, splits and assigns them to Sourcing, and gives the second-stage review.'])->save();
        $trainee->syncPermissions($operations->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Removes the role, unless someone still holds it.
     */
    public function down(): void
    {
        $trainee = Role::query()->where('name', 'Operation Assistant Trainee')->first();

        if ($trainee !== null && $trainee->users()->doesntExist()) {
            $trainee->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
