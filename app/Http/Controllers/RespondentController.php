<?php

namespace App\Http\Controllers;

use App\Domain\Submissions\SubmissionService;
use App\Models\FormSubmission;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RespondentController extends Controller
{
    public function show(Publication $publication, Request $request) { if($publication->access_mode==='authenticated'&&!$request->user())return redirect()->guest(route('login')); return view('respondent.landing',compact('publication')); }

    public function start(Publication $publication, Request $request, SubmissionService $service)
    {
        $data=$request->validate(['access_code'=>'nullable|string|max:100','invite'=>'nullable|string|max:200']);
        $anonymousKey=$request->session()->get('respondent_key'); if(!$anonymousKey){$anonymousKey=Str::random(64);$request->session()->put('respondent_key',$anonymousKey);}
        $submission=$service->start($publication,$request->user(),$data['access_code']??null,$data['invite']??$request->query('invite'),$anonymousKey);
        return redirect()->route('submissions.take',$submission);
    }

    public function take(FormSubmission $submission, Request $request)
    {
        $this->assertOwner($submission,$request); $submission->load('publication.form','formVersion.sections.components.options','formVersion.conditionalRules.actions','answers');
        return view('respondent.take',compact('submission'));
    }

    public function complete(FormSubmission $submission, Request $request)
    {
        $this->assertOwner($submission,$request); $submission->load('publication.form','answers.component.options','answers.component.scoringRule','answers.score'); return view('respondent.complete',compact('submission'));
    }

    public static function assertOwner(FormSubmission $submission, Request $request): void
    {
        $owned=$request->user()&&$submission->user_id===$request->user()->id;
        $anonymous=$submission->anonymous_key_hash&&$request->session()->has('respondent_key')&&hash_equals($submission->anonymous_key_hash,hash('sha256',$request->session()->get('respondent_key')));
        abort_unless($owned || $anonymous, 403);
    }
}
