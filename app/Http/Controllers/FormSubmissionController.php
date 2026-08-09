<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Organisation;
use Illuminate\Http\Request;

class FormSubmissionController extends Controller
{
    public function index(Request $request, Organisation $organisation)
    {
        abort_unless($request->user()->hasOrganisationPermission($organisation->id,'submissions.view'),403);
        $query=FormSubmission::with('publication.form','user')->where('organisation_id',$organisation->id)->withoutPatientAssignment();
        foreach(['status','grading_status','publication_id','user_id'] as $filter) if($request->filled($filter)) $query->where($filter,$request->input($filter));
        if($request->filled('form_id'))$query->whereHas('publication',fn($q)=>$q->where('form_id',$request->form_id));
        if($request->filled('from'))$query->whereDate('started_at','>=',$request->from); if($request->filled('to'))$query->whereDate('started_at','<=',$request->to);
        return view('submissions.index',['organisation'=>$organisation,'submissions'=>$query->latest()->paginate(25)->withQueryString(),'forms'=>Form::where('organisation_id',$organisation->id)->get()]);
    }
    public function show(FormSubmission $submission) { $this->authorize('view',$submission); return view('submissions.show',['submission'=>$submission->load('publication.form','formVersion','answers.component','answers.score','consentRecords')]); }
}
