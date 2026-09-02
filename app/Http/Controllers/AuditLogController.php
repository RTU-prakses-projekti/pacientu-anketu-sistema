<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organisation;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, ?Organisation $organisation = null) { if($organisation)abort_unless($request->user()->hasOrganisationPermission($organisation->id,'audit.view'),403);else abort_unless($request->user()->canAdministerSystem(),403);$query=AuditLog::query()->latest('created_at');if($organisation)$query->where('organisation_id',$organisation->id);return view('audit.index',['organisation'=>$organisation,'logs'=>$query->paginate(50)]); }
}
