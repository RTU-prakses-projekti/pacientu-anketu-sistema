<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Domain\Administration\CleanupService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAdministrationController extends Controller
{
    public function index(Request $request, Organisation $organisation)
    {
        abort_unless($request->user()->hasOrganisationPermission($organisation->id, 'users.manage'), 403);
        $roles = Role::where('scope', 'organisation')
            ->when(!$request->user()->canAdministerSystem(), fn ($query) => $query->where('name', '!=', 'doctor'))
            ->orderBy('display_name')
            ->get();

        return view('users.index', [
            'organisation' => $organisation,
            'memberships' => $organisation->memberships()->with('user', 'roles')->get(),
            'roles' => $roles,
        ]);
    }

    public function storeMembership(Request $request, Organisation $organisation, AuditService $audit)
    {
        abort_unless($request->user()->hasOrganisationPermission($organisation->id, 'users.manage'), 403);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'roles' => ['required', 'array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();
        if (!$user) return back()->withErrors(['email' => __('messages.membership_candidate_not_found')]);

        $requestedRoles = Role::where('scope', 'organisation')->whereIn('id', $data['roles'])->get();
        if (!$request->user()->canAdministerSystem() && $requestedRoles->contains('name', 'doctor')) {
            abort(403);
        }

        $membership = OrganisationMembership::firstOrCreate(
            ['organisation_id' => $organisation->id, 'user_id' => $user->id],
            ['is_active' => true],
        );
        $roleIds = $requestedRoles->pluck('id');
        if (!$request->user()->canAdministerSystem()) {
            $roleIds = $roleIds->merge($membership->roles()->where('name', 'doctor')->pluck('roles.id'));
        }
        $membership->roles()->sync($roleIds->unique()->values());
        $audit->record('membership.updated', $membership, $organisation->id, ['role_ids' => $roleIds->all()]);

        return back()->with('success', __('messages.saved'));
    }

    public function toggleUser(Request $request, User $user, AuditService $audit, CleanupService $cleanup)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
        abort_if($request->user()->is($user), 422, __('messages.cannot_disable_self'));
        DB::transaction(function () use ($user, $cleanup, $audit): void {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $cleanup->ensureCanDeactivate($user);
            $user->update(['is_active' => !$user->is_active]);
            $audit->record('user.status_changed', $user, null, ['is_active' => $user->is_active]);
        });
        return back();
    }

    public function destroyUser(Request $request, User $user, CleanupService $cleanup)
    {
        abort_unless($request->user()->canAdministerSystem(), 403);
        abort_if($request->user()->is($user), 422, __('messages.cannot_delete_self'));
        $cleanup->deleteUser($user);
        return redirect()->route('system.users')->with('success', __('messages.user_deleted'));
    }
}
