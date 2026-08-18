<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Domain\Administration\CleanupService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class SystemAdministrationController extends Controller
{
    public function users(Request $request, CleanupService $cleanup)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'organisation' => ['nullable', 'integer', 'exists:organisations,id'],
            'role' => ['nullable', 'integer', 'exists:roles,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $selectedRole = isset($filters['role'])
            ? Role::findOrFail($filters['role'])
            : null;
        $users = User::query()
            ->with(['globalRoles', 'memberships.organisation', 'memberships.roles'])
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $search = addcslashes(trim($filters['q']), '%_\\');
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                    if (ctype_digit($search)) $query->orWhere('users.id', (int) $search);
                });
            })
            ->when(isset($filters['organisation']) && (!$selectedRole || $selectedRole->scope !== 'organisation'),
                fn ($query) => $query->whereHas('memberships', fn ($membership) => $membership
                    ->where('organisation_id', $filters['organisation'])->where('is_active', true)))
            ->when($selectedRole?->scope === 'global',
                fn ($query) => $query->whereHas('globalRoles', fn ($role) => $role->whereKey($selectedRole->id)))
            ->when($selectedRole?->scope === 'organisation',
                fn ($query) => $query->whereHas('memberships', fn ($membership) => $membership
                    ->where('is_active', true)
                    ->when(isset($filters['organisation']), fn ($membership) => $membership->where('organisation_id', $filters['organisation']))
                    ->whereHas('roles', fn ($role) => $role->whereKey($selectedRole->id))))
            ->when(isset($filters['status']), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')->paginate(50)->withQueryString();

        return view('system.users', [
            'users' => $users,
            'organisations' => Organisation::orderBy('name')->get(),
            'roles' => Role::orderBy('scope')->orderBy('display_name')->get(),
            'filters' => $filters,
            'deleteEligibility' => $cleanup->userEligibilityMap($users->getCollection()),
        ]);
    }

    public function roles(Request $request)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        return view('system.roles', [
            'roles' => Role::with('permissions')->orderBy('scope')->orderBy('name')->get(),
            'permissions' => Permission::orderBy('name')->get(),
        ]);
    }

    public function updateRole(Request $request, Role $role, AuditService $audit)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $data = $request->validate(['permissions' => 'nullable|array', 'permissions.*' => 'integer|exists:permissions,id']);
        $role->permissions()->sync($data['permissions'] ?? []);
        $audit->record('role.permissions_updated', $role, null, ['permission_ids' => $data['permissions'] ?? []]);

        return back()->with('success', __('messages.saved'));
    }

    public function storeUser(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','confirmed',Password::min(12)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data, $audit) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'locale' => 'lv',
                'is_active' => true,
            ]);
            $audit->record('user.created', $user);

            return $user;
        });

        return redirect()->route('system.users.roles.edit', $user)
            ->with('success', __('messages.user_created'));
    }

    public function editUserRoles(Request $request, User $user)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        return view('system.user-roles', [
            'managedUser' => $user->load(['globalRoles', 'memberships.roles']),
            'globalRoles' => Role::where('scope', 'global')->orderBy('display_name')->get(),
            'organisationRoles' => Role::where('scope', 'organisation')->orderBy('display_name')->get(),
            'organisations' => Organisation::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateUserRoles(Request $request, User $user, AuditService $audit)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        $globalRoleIds = Role::where('scope', 'global')->pluck('id');
        $organisationRoleIds = Role::where('scope', 'organisation')->pluck('id');
        $activeOrganisations = Organisation::where('is_active', true)->orderBy('name')->get();
        $activeOrganisationIds = $activeOrganisations->modelKeys();
        $data = $request->validate([
            'global_roles' => ['nullable', 'array'],
            'global_roles.*' => ['integer', Rule::in($globalRoleIds->all())],
            'organisation_roles' => ['nullable', 'array'],
            'organisation_roles.*' => ['array'],
            'organisation_roles.*.*' => ['integer', Rule::in($organisationRoleIds->all())],
        ]);

        $submittedOrganisationRoles = $data['organisation_roles'] ?? [];
        foreach (array_keys($submittedOrganisationRoles) as $organisationId) {
            if (!ctype_digit((string) $organisationId) || !in_array((int) $organisationId, $activeOrganisationIds, true)) {
                throw ValidationException::withMessages([
                    'organisation_roles' => __('messages.invalid_organisation_role_scope'),
                ]);
            }
        }

        $selectedGlobalRoleIds = collect($data['global_roles'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $selectedByOrganisation = collect($submittedOrganisationRoles)
            ->map(fn ($ids) => collect($ids)->map(fn ($id) => (int) $id)->unique()->values());

        DB::transaction(function () use ($request, $user, $audit, $selectedGlobalRoleIds, $selectedByOrganisation, $activeOrganisations): void {
            $platformRole = Role::where('name', 'platform_admin')->where('scope', 'global')->lockForUpdate()->firstOrFail();
            $platformAdministrators = DB::table('user_roles')
                ->where('role_id', $platformRole->id)
                ->lockForUpdate()
                ->pluck('user_id');

            if ($request->user()->is($user)
                && $platformAdministrators->contains($user->id)
                && !$selectedGlobalRoleIds->contains($platformRole->id)
                && $platformAdministrators->count() === 1) {
                throw ValidationException::withMessages([
                    'global_roles' => __('messages.last_platform_admin_required'),
                ]);
            }

            $user->globalRoles()->sync($selectedGlobalRoleIds);

            foreach ($activeOrganisations as $organisation) {
                $roleIds = $selectedByOrganisation->get($organisation->id, collect());
                $membership = OrganisationMembership::where('organisation_id', $organisation->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($roleIds->isNotEmpty()) {
                    $membership ??= OrganisationMembership::create([
                        'organisation_id' => $organisation->id,
                        'user_id' => $user->id,
                        'is_active' => true,
                    ]);
                    $membership->update(['is_active' => true]);
                    $membership->roles()->sync($roleIds);
                } elseif ($membership) {
                    $membership->roles()->sync([]);
                    $membership->update(['is_active' => false]);
                }
            }

            $audit->record('user.roles_updated', $user, null, [
                'global_role_ids' => $selectedGlobalRoleIds->all(),
                'organisation_role_ids' => $selectedByOrganisation->map->all()->all(),
            ]);
        });

        return redirect()->route('system.users')->with('success', __('messages.roles_updated'));
    }
}
