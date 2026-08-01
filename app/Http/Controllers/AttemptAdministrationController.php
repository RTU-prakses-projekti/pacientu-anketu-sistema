<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\AttemptGrant;
use App\Models\FormSubmission;
use Illuminate\Http\Request;

class AttemptAdministrationController extends Controller
{
    public function grant(Request $request,FormSubmission $submission,AuditService $audit){$this->authorize('manage',$submission);$data=$request->validate(['reason'=>'required|string|max:1000']);AttemptGrant::create(['publication_id'=>$submission->publication_id,'user_id'=>$submission->user_id,'invitation_id'=>$submission->invitation_id,'anonymous_key_hash'=>$submission->anonymous_key_hash,'additional_attempts'=>1,'reason'=>$data['reason'],'granted_by'=>$request->user()->id]);$audit->record('attempt.granted',$submission,$submission->organisation_id);return back()->with('success',__('messages.saved'));}
    public function extend(Request $request,FormSubmission $submission,AuditService $audit){$this->authorize('manage',$submission);$data=$request->validate(['deadline_at'=>'required|date|after:now']);$submission->update(['deadline_at'=>$data['deadline_at']]);$audit->record('deadline.extended',$submission,$submission->organisation_id,['deadline_at'=>$submission->deadline_at?->toIso8601String()]);return back()->with('success',__('messages.saved'));}
    public function invalidate(Request $request,FormSubmission $submission,AuditService $audit){$this->authorize('manage',$submission);$data=$request->validate(['reason'=>'required|string|max:1000']);$submission->update(['status'=>'cancelled','invalidation_reason'=>$data['reason']]);$audit->record('attempt.invalidated',$submission,$submission->organisation_id);return back()->with('success',__('messages.saved'));}
}
