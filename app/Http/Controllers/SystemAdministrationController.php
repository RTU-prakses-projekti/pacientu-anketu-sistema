<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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

    public function createPlatformAdmin(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','confirmed',Password::min(12)->letters()->numbers()],
        ]);
        DB::transaction(function () use ($data, $audit) {
            $role = Role::where('name','platform_admin')->lockForUpdate()->firstOrFail();
            $user = User::create(['name'=>$data['name'],'email'=>strtolower($data['email']),'password'=>Hash::make($data['password']),'locale'=>'lv','is_active'=>true]);
            $user->globalRoles()->attach($role->id);
            $audit->record('platform_admin.created',$user,null);
        });
        return back()->with('success',__('messages.platform_admin_created'));
    }

    public function promotePlatformAdmin(Request $request, User $user, AuditService $audit)
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        abort_if($user->isPlatformAdmin(), 422, __('messages.already_platform_admin'));
        DB::transaction(function () use ($user, $audit) {
            $role = Role::where('name','platform_admin')->lockForUpdate()->firstOrFail();
            $user->globalRoles()->attach($role->id);
            $audit->record('platform_admin.promoted',$user,null);
        });
        return back()->with('success',__('messages.platform_admin_promoted'));
    }
}
