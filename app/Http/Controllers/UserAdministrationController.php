<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class UserAdministrationController extends Controller
{
    public function index(Request $request, Organisation $organisation){abort_unless($request->user()->hasOrganisationPermission($organisation->id,'users.manage'),403);return view('users.index',['organisation'=>$organisation,'memberships'=>$organisation->memberships()->with('user','roles')->get(),'roles'=>Role::where('scope','organisation')->get()]);}
    public function storeMembership(Request $request,Organisation $organisation,AuditService $audit){abort_unless($request->user()->hasOrganisationPermission($organisation->id,'users.manage'),403);$data=$request->validate(['email'=>'required|email','roles'=>'required|array','roles.*'=>'exists:roles,id']);$user=User::where('email',$data['email'])->where('is_active',true)->first();if(!$user)return back()->withErrors(['email'=>__('messages.membership_candidate_not_found')]);$membership=OrganisationMembership::firstOrCreate(['organisation_id'=>$organisation->id,'user_id'=>$user->id],['is_active'=>true]);$allowed=Role::where('scope','organisation')->whereIn('id',$data['roles'])->pluck('id');$membership->roles()->sync($allowed);$audit->record('membership.updated',$membership,$organisation->id,['role_ids'=>$allowed->all()]);return back()->with('success',__('messages.saved'));}
    public function toggleUser(Request $request,User $user,AuditService $audit){abort_unless($request->user()->isPlatformAdmin(),403);abort_if($request->user()->is($user),422,__('messages.cannot_disable_self'));$user->update(['is_active'=>!$user->is_active]);$audit->record('user.status_changed',$user,null,['is_active'=>$user->is_active]);return back();}
}
