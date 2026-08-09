<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Domain\Exports\ExportService;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function index(Request $request, Organisation $organisation) { abort_unless($request->user()->hasOrganisationPermission($organisation->id,'exports.create'),403); return view('exports.index',['organisation'=>$organisation,'exports'=>Export::where('organisation_id',$organisation->id)->latest()->get(),'forms'=>Form::where('organisation_id',$organisation->id)->get()]); }
    public function store(Request $request, Organisation $organisation, ExportService $service, AuditService $audit)
    {
        abort_unless($request->user()->hasOrganisationPermission($organisation->id,'exports.create'),403);$data=$request->validate(['form_id'=>'nullable|integer|exists:forms,id','format'=>'required|in:csv,xlsx']);if(!empty($data['form_id']))abort_unless(Form::where('id',$data['form_id'])->where('organisation_id',$organisation->id)->exists(),404);
        $export=Export::create(['public_id'=>(string)Str::uuid(),'organisation_id'=>$organisation->id,'requested_by'=>$request->user()->id,'form_id'=>$data['form_id']??null,'format'=>$data['format'],'status'=>'pending']);$audit->record('export.created',$export,$organisation->id,['format'=>$export->format]);
        $count=FormSubmission::where('organisation_id',$organisation->id)->withoutPatientAssignment()->when($export->form_id,fn($q)=>$q->whereHas('publication',fn($p)=>$p->where('form_id',$export->form_id)))->count(); if($count>500){GenerateExport::dispatch($export->id);return back()->with('success',__('messages.export_queued'));}$service->generate($export);return back()->with('success',__('messages.export_ready'));
    }
    public function download(Request $request, Export $export, AuditService $audit) { abort_unless($request->user()->hasOrganisationPermission($export->organisation_id,'exports.download'),403);abort_unless($export->status==='completed'&&$export->storage_path&&(!$export->expires_at||$export->expires_at->isFuture()),404);$audit->record('export.downloaded',$export,$export->organisation_id);return Storage::disk('local')->download($export->storage_path,'form-export.'.$export->format); }
}
