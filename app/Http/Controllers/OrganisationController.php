<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Domain\Administration\CleanupService;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganisationController extends Controller
{
    public function index(CleanupService $cleanup) { abort_unless(auth()->user()->canAdministerSystem(), 403);$organisations=Organisation::withCount(['memberships','forms'])->get();return view('organisations.index',['organisations'=>$organisations,'deleteEligibility'=>$organisations->mapWithKeys(fn($organisation)=>[$organisation->id=>$cleanup->organisationEligibility($organisation)])]); }
    public function create() { abort_unless(auth()->user()->canAdministerSystem(), 403); return view('organisations.edit', ['organisation' => new Organisation]); }
    public function store(Request $request, AuditService $audit) { abort_unless(auth()->user()->canAdministerSystem(), 403); $data=$request->validate(['name'=>'required|string|max:255','slug'=>'nullable|string|max:255|unique:organisations,slug']); $organisation=Organisation::create(['name'=>$data['name'],'slug'=>$data['slug'] ?: Str::slug($data['name']),'is_active'=>true]); $audit->record('organisation.created',$organisation,$organisation->id); return redirect()->route('organisations.index')->with('success', __('messages.saved')); }
    public function edit(Organisation $organisation) { $this->authorize('update', $organisation); return view('organisations.edit', compact('organisation')); }
    public function update(Request $request, Organisation $organisation, AuditService $audit) { $this->authorize('update',$organisation); $data=$request->validate(['name'=>'required|string|max:255','is_active'=>'sometimes|boolean']); $organisation->update(['name'=>$data['name'],'is_active'=>(bool)($data['is_active']??false)]); $audit->record('organisation.updated',$organisation,$organisation->id); return back()->with('success',__('messages.saved')); }
    public function destroy(Organisation $organisation, CleanupService $cleanup) { abort_unless(auth()->user()->canAdministerSystem(),403);$cleanup->deleteOrganisation($organisation);return redirect()->route('organisations.index')->with('success',__('messages.organisation_deleted')); }
}
