<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller implements HasMiddleware
{
    /**
     * Why a built-in permission (App\Permissions) isn't renamed or deleted.
     */
    private const BUILT_IN = 'That permission is built in — the app checks it by its name, so it can\'t be renamed or deleted. Switch it on or off for a role on the Roles page.';

    public static function middleware(): array
    {
        return [
            new Middleware('permission:permissions.view', only: ['index', 'show']),
            new Middleware('permission:permissions.create', only: ['create', 'store']),
            new Middleware('permission:permissions.edit', only: ['edit', 'update']),
            new Middleware('permission:permissions.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $permissions = Permission::query()
            ->withCount('roles')
            ->when($request->string('search')->trim()->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.permissions.index', [
            'permissions' => $permissions,
            'search' => $request->string('search')->toString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.permissions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name'],
        ]);

        Permission::create(['name' => $validated['name']]);

        return redirect()->route('admin.permissions.index')->with('status', 'Permission created successfully.');
    }

    /**
     * Renaming one the code checks (App\Permissions::isBuiltIn()) is
     * refused: whoever has it would lose what it gives them.
     */
    public function edit(Permission $permission): View|RedirectResponse
    {
        if (Permissions::isBuiltIn($permission->name)) {
            return redirect()->route('admin.permissions.index')->with('error', self::BUILT_IN);
        }

        return view('admin.permissions.edit', [
            'permission' => $permission,
        ]);
    }

    public function update(Request $request, Permission $permission): RedirectResponse
    {
        if (Permissions::isBuiltIn($permission->name)) {
            return redirect()->route('admin.permissions.index')->with('error', self::BUILT_IN);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('permissions', 'name')->ignore($permission->id)],
        ]);

        $permission->name = $validated['name'];
        $permission->save();

        return redirect()->route('admin.permissions.index')->with('status', 'Permission updated successfully.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        if (Permissions::isBuiltIn($permission->name)) {
            return back()->with('error', self::BUILT_IN);
        }

        if ($permission->roles()->exists()) {
            return back()->with('error', 'Cannot delete a permission that is still assigned to roles.');
        }

        $permission->delete();

        return redirect()->route('admin.permissions.index')->with('status', 'Permission deleted successfully.');
    }
}
