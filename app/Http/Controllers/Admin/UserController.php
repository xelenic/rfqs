<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountCreated;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Throwable;

class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:users.view', only: ['index', 'show']),
            new Middleware('permission:users.create', only: ['store', 'sendPasswordLink']),
            new Middleware('permission:users.edit', only: ['update']),
            new Middleware('permission:users.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $users = User::query()
            ->with('roles')
            ->when($request->string('search')->trim()->toString(), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $request->string('search')->toString(),
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    /**
     * The roles $user can give someone: any, for an Admin — and every one
     * but Admin for anyone else who adds people (HR Manager).
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $user): Collection
    {
        return Role::query()
            ->when(! $user->hasRole('Admin'), fn ($roles) => $roles->where('name', '!=', 'Admin'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Adds someone — HR Manager or Admin. By default with no password of
     * their own yet: they're emailed a link to set it (AccountCreated, good
     * for a week and once); if the email can't be sent, the account still
     * stands, and says so — Send link tries again. An Admin can set a
     * password for them instead (sign_in "password"), and then no email
     * goes. Only an Admin can make an Admin.
     */
    public function store(Request $request): RedirectResponse
    {
        $isAdmin = $request->user()->hasRole('Admin');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'sign_in' => ['nullable', Rule::in($isAdmin ? ['email_link', 'password'] : ['email_link'])],
            'password' => ['exclude_unless:sign_in,password', 'required', 'string', 'min:8', 'confirmed'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles($request->user())->pluck('name'))],
        ], [
            'sign_in.in' => 'Only an Admin can set a password for someone — email them a link instead.',
            'roles.*.in' => 'Pick from the roles listed.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'create')->withInput($request->except(['password', 'password_confirmation']));
        }

        $validated = $validator->validated();
        $setsPassword = ($validated['sign_in'] ?? 'email_link') === 'password';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Without one set here nobody knows it — they set their own from the email.
            'password' => Hash::make($setsPassword ? $validated['password'] : Str::random(64)),
        ]);

        $user->syncRoles($validated['roles'] ?? []);

        if ($setsPassword) {
            return redirect()->route('admin.users.index')->with('status', 'User created — they can sign in with the password you set.');
        }

        return $this->emailPasswordLink($request, $user, "User created — an email is on its way to {$user->email} to set their password.");
    }

    /**
     * Emails $user a fresh link to set their password — for a link that
     * expired or went astray. Any link sent before stops working.
     */
    public function sendPasswordLink(Request $request, User $user): RedirectResponse
    {
        return $this->emailPasswordLink($request, $user, "A fresh link to set their password is on its way to {$user->email}.");
    }

    /**
     * Sends AccountCreated to $user with a new "new_users" token, then back
     * to the list saying $sent — or, if the mail couldn't go, why not.
     */
    private function emailPasswordLink(Request $request, User $user, string $sent): RedirectResponse
    {
        try {
            $user->notify(new AccountCreated(Password::broker('new_users')->createToken($user), $request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('admin.users.index')
                ->with('error', "The email to {$user->email} couldn't be sent — check the mail settings, then use Send link to try again.");
        }

        return redirect()->route('admin.users.index')->with('status', $sent);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles($request->user())->pluck('name'))],
        ], [
            'roles.*.in' => 'Pick from the roles listed.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'edit')->withInput();
        }

        $validated = $validator->validated();

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();
        $user->syncRoles($validated['roles'] ?? []);

        return redirect()->route('admin.users.index')->with('status', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted successfully.');
    }
}
