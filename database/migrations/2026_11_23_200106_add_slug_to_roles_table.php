<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The slug each role the code knows gets, by the names it has had: its
     * name now, then the ones it had before (renamed on the Roles page or by
     * an earlier migration). A role called anything else gets the slug of
     * its name.
     *
     * @var array<string, array<int, string>>
     */
    private const KNOWN_ROLES = [
        'admin' => ['Admin'],
        'business-development' => ['Business Development'],
        'hbds-operations' => ['HBDs Operations', 'Senior Operations', 'Operations'],
        'operation-assistant-trainee' => ['Operation Assistant Trainee'],
        'sourcing' => ['Sourcing'],
        'head-of-business-development' => ['Head of Business Development'],
        'data-entry' => ['Data Entry'],
        'gms-operations' => ['GMs Operations', 'GM Assistant'],
        'general-manager' => ['General Manager'],
        'hr-executive' => ['HR Executive', 'HR Manager'],
    ];

    /**
     * Since 2026-11-24, at the user's request, the code knows each role by a
     * slug that renaming it never changes (App\Models\Role). Gives each role
     * its slug, then keys Settings → Messaging's saved rules by slug, not
     * name.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        $roles = DB::table('roles')->orderBy('id')->get(['id', 'name', 'guard_name']);
        $taken = [];
        $slugs = [];

        // A role under its name now first, then under a name it had, then
        // the rest — so a role that kept its name keeps its slug.
        $rank = function (object $role): int {
            foreach (self::KNOWN_ROLES as $names) {
                $at = array_search($role->name, $names, true);

                if ($at !== false) {
                    return $at === 0 ? 0 : 1;
                }
            }

            return 2;
        };

        foreach ($roles->sortBy(fn (object $role) => [$rank($role), $role->id]) as $role) {
            $known = collect(self::KNOWN_ROLES)->search(fn (array $names) => in_array($role->name, $names, true));
            $base = $known !== false ? $known : (Str::slug($role->name) ?: 'role');
            $free = fn (string $slug) => ! isset($taken[$role->guard_name][$slug]);

            // Its known slug, else (an older copy of a known role) its own
            // name's, else numbered.
            $slug = collect([$base, Str::slug($role->name)])->filter()->first($free);

            for ($number = 2; $slug === null; $number++) {
                $slug = $free("{$base}-{$number}") ? "{$base}-{$number}" : null;
            }

            $taken[$role->guard_name][$slug] = true;
            $slugs[$role->name] = $slug;
            DB::table('roles')->where('id', $role->id)->update(['slug' => $slug]);
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['slug', 'guard_name']);
        });

        $this->rekeyMessageRules(fn (string $name) => $slugs[$name] ?? Str::slug($name));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = DB::table('roles')->whereNotNull('slug')->pluck('name', 'slug')->all();

        $this->rekeyMessageRules(fn (string $slug) => $names[$slug] ?? $slug);

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['slug', 'guard_name']);
            $table->dropColumn('slug');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Settings → Messaging's rules (role => the roles it may write to), each
     * role given by $key instead.
     *
     * @param  callable(string): string  $key
     */
    private function rekeyMessageRules(callable $key): void
    {
        $saved = json_decode((string) DB::table('settings')->where('key', 'message_roles')->value('value'), true);

        if (! is_array($saved)) {
            return;
        }

        $rules = collect($saved)
            ->filter(fn (mixed $allowed) => is_array($allowed))
            ->mapWithKeys(fn (array $allowed, string $role) => [$key($role) => collect($allowed)->map(fn (string $to) => $key($to))->unique()->sort()->values()->all()])
            ->all();

        DB::table('settings')->where('key', 'message_roles')->update(['value' => json_encode($rules)]);
        Cache::forget('settings');
    }
};
