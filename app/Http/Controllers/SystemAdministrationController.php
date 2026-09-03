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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class SystemAdministrationController extends Controller
{
    public function users(Request $request, CleanupService $cleanup)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);

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
            'roles' => Role::where('name', '!=', 'platform_admin')->orderBy('scope')->orderBy('display_name')->get(),
            'filters' => $filters,
            'deleteEligibility' => $cleanup->userEligibilityMap($users->getCollection()),
        ]);
    }

    public function roles(Request $request)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);

        $permissionGroups = [
            ['key' => 'general', 'label' => __('messages.permission_group_general'), 'permissions' => ['organisation.view', 'organisation.manage', 'users.manage', 'audit.view']],
            ['key' => 'forms', 'label' => __('messages.permission_group_forms'), 'permissions' => ['forms.view', 'forms.create', 'forms.update', 'forms.publish', 'forms.archive']],
            ['key' => 'submissions', 'label' => __('messages.permission_group_submissions'), 'permissions' => ['submissions.view', 'exports.create', 'exports.download', 'anonymized_results.view']],
            ['key' => 'doctor', 'label' => __('messages.permission_group_doctor'), 'permissions' => ['doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view']],
        ];
        $permissionMap = Permission::whereIn('name', collect($permissionGroups)->pluck('permissions')->flatten()->all())
            ->get()->keyBy('name');
        $permissionGroups = collect($permissionGroups)->map(fn (array $group): array => [
            ...$group,
            'permissions' => collect($group['permissions'])->map(fn (string $name) => $permissionMap->get($name))->filter()->values(),
        ])->all();

        return view('system.roles', [
            'roles' => Role::with('permissions')->where('name', '!=', 'platform_admin')->orderBy('scope')->orderBy('name')->get(),
            'permissionGroups' => $permissionGroups,
        ]);
    }

    public function updateRole(Request $request, Role $role, AuditService $audit)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
        abort_if($role->name === 'platform_admin', 403);
        $data = $request->validate(['permissions' => 'nullable|array', 'permissions.*' => 'integer|exists:permissions,id']);
        $doctorOnlyPermissionIds = Permission::whereIn('name', [
            'doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view',
        ])->pluck('id');
        $submittedPermissionIds = collect($data['permissions'] ?? [])->map(fn ($id) => (int) $id);
        if ($role->is_system && $role->name !== 'doctor'
            && $submittedPermissionIds->intersect($doctorOnlyPermissionIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => __('messages.doctor_permissions_system_role_denied')]);
        }
        $role->permissions()->sync($data['permissions'] ?? []);
        $audit->record('role.permissions_updated', $role, null, ['permission_ids' => $data['permissions'] ?? []]);

        return back()->with('success', __('messages.saved'));
    }

    public function storeRole(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $name = Str::slug(trim($data['display_name']), '_');
        if ($name === '' || in_array($name, Role::SYSTEM_NAMES, true)) {
            throw ValidationException::withMessages(['display_name' => __('messages.reserved_role_name')]);
        }

        $role = DB::transaction(function () use ($data, $name, $audit): Role {
            if (Role::where('name', $name)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['display_name' => __('messages.role_name_already_exists')]);
            }
            $role = Role::create([
                'name' => $name,
                'display_name' => trim($data['display_name']),
                'scope' => 'organisation',
                'is_system' => false,
            ]);
            $permissionIds = collect($data['permissions'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $role->permissions()->sync($permissionIds);
            $audit->record('role.created', $role, null, ['permission_ids' => $permissionIds->all(), 'scope' => 'organisation']);
            return $role;
        });

        return redirect()->route('system.roles')->with('success', __('messages.role_created', ['role' => $role->display_name]));
    }

    public function destroyRole(Request $request, Role $role, AuditService $audit)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
        DB::transaction(function () use ($role, $audit): void {
            $role = Role::lockForUpdate()->findOrFail($role->id);
            if ($role->is_system || in_array($role->name, Role::SYSTEM_NAMES, true)) {
                throw ValidationException::withMessages(['role' => __('messages.system_role_delete_denied')]);
            }
            if (DB::table('user_roles')->where('role_id', $role->id)->exists()
                || DB::table('membership_roles')->where('role_id', $role->id)->exists()) {
                throw ValidationException::withMessages(['role' => __('messages.assigned_role_delete_denied')]);
            }
            $role->permissions()->detach();
            $audit->record('role.deleted', $role, null, ['name' => $role->name, 'scope' => $role->scope]);
            $role->delete();
        });

        return redirect()->route('system.roles')->with('success', __('messages.role_deleted'));
    }

    public function storeUser(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
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
        abort_unless($request->user()->canAdministerSystem(), 403);

        return view('system.user-roles', [
            'managedUser' => $user->load(['globalRoles', 'memberships.roles']),
            'globalRoles' => Role::where('scope', 'global')->where('name', '!=', 'platform_admin')->orderBy('display_name')->get(),
            'organisationRoles' => Role::where('scope', 'organisation')->orderBy('display_name')->get(),
            'organisations' => Organisation::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function updateUserRoles(Request $request, User $user, AuditService $audit)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);

        $globalRoleIds = Role::where('scope', 'global')->where('name', 'administrator')->pluck('id');
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
            $rootRoleIds = $user->globalRoles()->where('name', 'platform_admin')->pluck('roles.id');
            $user->globalRoles()->sync($rootRoleIds->merge($selectedGlobalRoleIds)->unique()->values());

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
