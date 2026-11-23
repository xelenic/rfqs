<?php

namespace App;

/**
 * Every permission the app checks, as the Roles page lists them: by area,
 * each with what it lets someone do. Since 2026-11-24, at the user's
 * request, each feature a role used to have by name is a permission here,
 * switched on or off for a role on its Roles page.
 *
 * A feature lists the roles (by slug) that have it to begin with: a role is
 * given them when it's made (App\Models\Role), and migration
 * add_feature_permissions gave them to the roles there already. A page's own
 * view/create/edit/delete permissions have no defaults here — the seeders
 * give those. The code checks a permission by its name, so a built-in one
 * can't be renamed or deleted (Admin\PermissionController).
 */
final class Permissions
{
    /**
     * The areas, each permission's name => [label, what it lets someone do,
     * the role slugs that have it to begin with — null for a page's own].
     *
     * @var array<string, array<string, array{label: string, hint: string, roles: array<int, string>|null}>>
     */
    public const AREAS = [
        'RFQs' => [
            'rfqs.view' => ['label' => 'See RFQs', 'hint' => 'Open RFQs and the lists of their step.', 'roles' => null],
            'rfqs.create' => ['label' => 'Create RFQs', 'hint' => 'Add new RFQs.', 'roles' => null],
            'rfqs.edit' => ['label' => 'Work on RFQs', 'hint' => 'Take their step\'s actions on an RFQ, and edit it where their role may.', 'roles' => null],
            'rfqs.delete' => ['label' => 'Delete comments', 'hint' => 'Delete anyone\'s comment on an RFQ, not only their own.', 'roles' => null],
            'rfqs.list-all' => ['label' => 'All RFQs', 'hint' => 'All RFQs in the sidebar — every RFQ, whatever its status or step.', 'roles' => ['admin', 'general-manager', 'hbds-operations', 'operation-assistant-trainee']],
            'rfqs.change-status' => ['label' => 'Hold, cancel and resume', 'hint' => 'Put an RFQ or a part on hold, cancel it, or set it going again.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee']],
            'rfqs.change-priority' => ['label' => 'Change priority', 'hint' => 'Change an RFQ\'s priority level when editing it.', 'roles' => ['admin', 'business-development', 'hbds-operations']],
            'rfqs.return-to-senior-ops' => ['label' => 'Send Sourcing parts back to Senior Operations', 'hint' => 'Send a part still with Sourcing back to Senior Operations to assign again, for the member.', 'roles' => ['admin']],
            'rfqs.critical-monitor' => ['label' => 'Critical monitor', 'hint' => 'The top bar\'s Critical monitor — what\'s overdue, idle or urgent across the operation.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee']],
            'rfqs.clear-data' => ['label' => 'Clear all RFQ data', 'hint' => 'Settings → Danger Zone: delete every RFQ and everything on them.', 'roles' => ['admin']],
        ],
        'Reports' => [
            'reports.time-spent' => ['label' => 'Time Spent report', 'hint' => 'How long each step took, and the deadlines exceeded.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'hr-executive']],
            'reports.performance' => ['label' => 'Performance reports', 'hint' => 'Sourcing Daily, Sourcing Summary, Sourcing Monthly, Data Entry Daily and the RFQ Tracking Sheet.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'hr-executive']],
            'reports.sourcing-figures' => ['label' => 'Change Sourcing Summary figures', 'hint' => 'Set expected RFQs, and correct supplier calls, on the Sourcing Summary.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee']],
        ],
        'Attendance and hours' => [
            'attendance.keep' => ['label' => 'Keep the attendance sheet', 'hint' => 'Fill in and submit the daily attendance sheet.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee']],
            'attendance.approve' => ['label' => 'Approve attendance', 'hint' => 'Approve a submitted attendance sheet, or return it to be corrected.', 'roles' => ['admin', 'hr-executive']],
            'working-hours.manage' => ['label' => 'Role and personal hours', 'hint' => 'Give a role, or one person, working hours of their own.', 'roles' => ['admin', 'hr-executive']],
        ],
        'Users' => [
            'users.view' => ['label' => 'See users', 'hint' => 'The Users page, and each person\'s page.', 'roles' => null],
            'users.create' => ['label' => 'Add users', 'hint' => 'Add people, and send them a link to set their password.', 'roles' => null],
            'users.edit' => ['label' => 'Edit users', 'hint' => 'Change people\'s roles and job categories.', 'roles' => null],
            'users.delete' => ['label' => 'Delete users', 'hint' => 'Delete someone\'s account.', 'roles' => null],
            'users.change-details' => ['label' => 'Change names and emails', 'hint' => 'Their own on Settings → Profile, and — with Edit users — other people\'s.', 'roles' => ['admin', 'hr-executive']],
            'users.set-password' => ['label' => 'Set passwords', 'hint' => 'Type a password for someone, instead of emailing them a link.', 'roles' => ['admin']],
            'users.deactivate' => ['label' => 'Deactivate accounts', 'hint' => 'Deactivate an account so it can\'t sign in, or activate it again.', 'roles' => ['admin']],
            'users.impersonate' => ['label' => 'Log in as someone', 'hint' => 'Sign in as another person — never an Admin — to see and do what they do.', 'roles' => ['admin']],
            'users.sourcing-setup' => ['label' => 'Sourcing members\' setup', 'hint' => 'Set a Sourcing member\'s expected RFQs, and their usual Data Entry person.', 'roles' => ['admin']],
        ],
        'Roles and permissions' => [
            'roles.view' => ['label' => 'See roles', 'hint' => 'The Roles page.', 'roles' => null],
            'roles.create' => ['label' => 'Add roles', 'hint' => 'Add a role.', 'roles' => null],
            'roles.edit' => ['label' => 'Edit roles', 'hint' => 'Rename a role, and switch its permissions on or off.', 'roles' => null],
            'roles.delete' => ['label' => 'Delete roles', 'hint' => 'Delete a role nobody holds.', 'roles' => null],
            'permissions.view' => ['label' => 'See permissions', 'hint' => 'The Permissions page.', 'roles' => null],
            'permissions.create' => ['label' => 'Add permissions', 'hint' => 'Add a permission of their own.', 'roles' => null],
            'permissions.edit' => ['label' => 'Edit permissions', 'hint' => 'Rename a permission that was added there.', 'roles' => null],
            'permissions.delete' => ['label' => 'Delete permissions', 'hint' => 'Delete a permission that was added there, once no role has it.', 'roles' => null],
        ],
        'Configuration' => [
            'settings.manage' => ['label' => 'Application settings', 'hint' => 'Settings\' own tabs: application, WC validation, RFQ numbers, working hours, half day, targets, idle alerts, extra hours and messaging.', 'roles' => ['admin']],
            'job-categories.manage' => ['label' => 'Job categories', 'hint' => 'Add, edit and delete the job categories the Assign Sourcing wizard offers.', 'roles' => ['admin', 'hbds-operations', 'operation-assistant-trainee', 'gms-operations']],
            'message-groups.manage' => ['label' => 'Group chats', 'hint' => 'Set up, change and delete group chats in Messages.', 'roles' => ['admin']],
        ],
        'System' => [
            'logs.view' => ['label' => 'Logs', 'hint' => 'Read and download the application\'s log files.', 'roles' => ['admin']],
            'backups.manage' => ['label' => 'Backups', 'hint' => 'Make, download, upload, schedule and restore backups.', 'roles' => ['admin']],
        ],
    ];

    /**
     * The area a permission made on the Permissions page is listed under.
     */
    public const OTHER_AREA = 'Other';

    /**
     * Every built-in permission's name.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_merge(...array_values(array_map(array_keys(...), self::AREAS)));
    }

    /**
     * Whether the code checks $name — one that can't be renamed or deleted.
     */
    public static function isBuiltIn(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * What $name is called on the Roles page — itself, for one made on the
     * Permissions page.
     */
    public static function label(string $name): string
    {
        return self::find($name)['label'] ?? $name;
    }

    /**
     * What $name lets someone do — null for one made on the Permissions page.
     */
    public static function hint(string $name): ?string
    {
        return self::find($name)['hint'] ?? null;
    }

    /**
     * The area $name is listed under on the Roles page.
     */
    public static function areaOf(string $name): string
    {
        foreach (self::AREAS as $area => $permissions) {
            if (array_key_exists($name, $permissions)) {
                return $area;
            }
        }

        return self::OTHER_AREA;
    }

    /**
     * The features the role with $slug has to begin with.
     *
     * @return array<int, string>
     */
    public static function defaultsFor(string $slug): array
    {
        return collect(self::AREAS)
            ->flatMap(fn (array $permissions) => $permissions)
            ->filter(fn (array $permission) => in_array($slug, $permission['roles'] ?? [], true))
            ->keys()
            ->all();
    }

    /**
     * $name's entry in AREAS, or null.
     *
     * @return array{label: string, hint: string, roles: array<int, string>|null}|null
     */
    private static function find(string $name): ?array
    {
        return self::AREAS[self::areaOf($name)][$name] ?? null;
    }
}
