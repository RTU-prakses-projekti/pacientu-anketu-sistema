<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Form;
use App\Models\Invitation;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Form $form, Publication $publication, AuditService $audit)
    {
        $this->authorize('publish',$form); abort_unless($publication->form_id===$form->id&&$publication->access_mode==='invitation',404);
        $data=$request->validate(['recipient_reference'=>'nullable|string|max:255','max_uses'=>'required|integer|min:1|max:100','expires_at'=>'nullable|date']);
        $plain=Str::random(48); $invitation=$publication->invitations()->create([...$data,'token_hash'=>hash('sha256',$plain),'uses'=>0]);
        $audit->record('invitation.created',$invitation,$form->organisation_id,['max_uses'=>$invitation->max_uses]);
        return back()->with('success',__('messages.invitation_created'))->with('invitation_url',route('publications.show',['publication'=>$publication,'invite'=>$plain]));
    }
    public function revoke(Form $form, Publication $publication, Invitation $invitation, AuditService $audit) { $this->authorize('publish',$form); abort_unless($invitation->publication_id===$publication->id&&$publication->form_id===$form->id,404); $invitation->update(['revoked_at'=>now()]); $audit->record('invitation.revoked',$invitation,$form->organisation_id); return back(); }
}
