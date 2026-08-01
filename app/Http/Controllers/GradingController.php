<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Http\Requests\GradeSubmissionRequest;
use App\Models\AnswerScore;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GradingController extends Controller
{
    public function update(GradeSubmissionRequest $request, FormSubmission $submission, AuditService $audit)
    {
        DB::transaction(function()use($request,$submission,$audit){$submission->load('answers.component','answers.score');foreach($request->scores as $answerId=>$data){$answer=$submission->answers->firstWhere('id',(int)$answerId);if(!$answer||!$answer->component->manual_grading)continue;$max=(float)$answer->component->max_points;if((float)$data['points']>$max)throw ValidationException::withMessages(['scores.'.$answerId=>__('messages.points_exceed_max')]);AnswerScore::updateOrCreate(['submission_answer_id'=>$answer->id],['automatic_points'=>$answer->score?->automatic_points??0,'manual_points'=>$data['points'],'final_points'=>(float)($answer->score?->automatic_points??0)+(float)$data['points'],'reviewer_comment'=>$data['comment']??null,'graded_by'=>$request->user()->id,'graded_at'=>now()]);}$manual=(float)AnswerScore::whereHas('answer',fn($q)=>$q->where('form_submission_id',$submission->id))->sum('manual_points');$final=(float)$submission->automatic_points+$manual;$submission->update(['manual_points'=>$manual,'final_points'=>$final,'percentage'=>(float)$submission->maximum_points>0?round($final/(float)$submission->maximum_points*100,2):null,'grading_status'=>$request->boolean('finalize')?'complete':'in_progress','status'=>$request->boolean('finalize')?'graded':'awaiting_grading']);$audit->record($request->boolean('finalize')?'grading.finalized':'grading.saved',$submission,$submission->organisation_id);});return back()->with('success',__('messages.saved'));
    }
}
