<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class SystemAdministrationController extends Controller
{
    public function users(Request $request)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);

        return view('system.users', ['users' => User::with('globalRoles')->orderBy('name')->paginate(50)]);
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
}
