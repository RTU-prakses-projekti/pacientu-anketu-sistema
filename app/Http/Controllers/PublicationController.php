<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Http\Requests\StorePublicationRequest;
use App\Models\Form;
use App\Models\Publication;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PublicationController extends Controller
{
    public function store(StorePublicationRequest $request, Form $form, AuditService $audit)
    {
        $data=$request->validated(); $version=$form->versions()->findOrFail($data['form_version_id']); abort_unless($version->status==='published',422);
        $publication=Publication::create([
            ...collect($data)->except(['access_code','timer_enabled','correct_answers_visible','anonymous_allowed','identified_required','consent_required','autosave_enabled','resume_enabled'])->all(),
            'organisation_id'=>$form->organisation_id,'form_id'=>$form->id,'public_key'=>Str::lower(Str::random(20)),
            'access_code_hash'=>!empty($data['access_code'])?Hash::make($data['access_code']):null,
            'timer_enabled'=>(bool)($data['timer_enabled']??false),'correct_answers_visible'=>(bool)($data['correct_answers_visible']??false),
            'anonymous_allowed'=>(bool)($data['anonymous_allowed']??false),'identified_required'=>(bool)($data['identified_required']??false),
            'consent_required'=>(bool)($data['consent_required']??false),'autosave_enabled'=>(bool)($data['autosave_enabled']??false),'resume_enabled'=>(bool)($data['resume_enabled']??false),
        ]);
        $audit->record('publication.created',$publication,$form->organisation_id,['access_mode'=>$publication->access_mode]);
        return redirect()->route('forms.show',$form)->with('success',__('messages.publication_created'));
    }

    public function toggle(Form $form, Publication $publication, AuditService $audit)
    {
        $this->authorize('publish',$form); abort_unless($publication->form_id===$form->id,404); abort_if($publication->status!=='active'&&$form->status==='archived',422,__('messages.archived_form_cannot_publish')); $publication->update(['status'=>$publication->status==='active'?'inactive':'active']); $audit->record('publication.status_changed',$publication,$form->organisation_id,['status'=>$publication->status]); return back();
    }
}
