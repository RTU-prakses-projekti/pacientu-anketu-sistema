<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    public function store(Request $request, Form $form, FormVersion $version, AuditService $audit) { $this->authorize('update',$form);abort_unless($version->form_id===$form->id&&$version->status==='draft',404);$data=$request->validate(['file'=>'required|file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx']);$file=$data['file'];$name=Str::uuid().'.'.$file->guessExtension();$path=$file->storeAs('attachments/'.$form->organisation_id,$name,'local');$attachment=Attachment::create(['organisation_id'=>$form->organisation_id,'attachable_type'=>$version->getMorphClass(),'attachable_id'=>$version->id,'uploaded_by'=>$request->user()->id,'disk'=>'local','storage_path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType()?:'application/octet-stream','size'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),'status'=>'ready']);$audit->record('attachment.created',$attachment,$form->organisation_id,['mime'=>$attachment->mime_type,'size'=>$attachment->size]);return back()->with('success',__('messages.saved'));}
    public function download(Request $request, Attachment $attachment) { abort_unless($request->user()&&$request->user()->hasOrganisationPermission($attachment->organisation_id,'forms.view'),403);return $this->fileResponse($attachment);}
    public function respondentDownload(Request $request, FormSubmission $submission, Attachment $attachment)
    {
        RespondentController::assertOwner($submission, $request);
        abort_unless(
            $attachment->status === 'ready'
            && $attachment->attachable_type === (new FormVersion())->getMorphClass()
            && $attachment->attachable_id === $submission->form_version_id
            && $attachment->organisation_id === $submission->organisation_id,
            404
        );

        return $this->fileResponse($attachment);
    }
    public function destroy(Request $request, Attachment $attachment) { abort_unless($request->user()&&$request->user()->hasOrganisationPermission($attachment->organisation_id,'forms.update'),403);$version=$attachment->attachable;abort_unless($version instanceof FormVersion&&$version->status==='draft',403);Storage::disk($attachment->disk)->delete($attachment->storage_path);$attachment->delete();return back()->with('success',__('messages.deleted'));}

    private function fileResponse(Attachment $attachment)
    {
        abort_unless($attachment->status === 'ready' && Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);
        if (str_starts_with($attachment->mime_type, 'image/')) {
            return response()->file(Storage::disk($attachment->disk)->path($attachment->storage_path), [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        return Storage::disk($attachment->disk)->download($attachment->storage_path,$attachment->original_name,['X-Content-Type-Options'=>'nosniff']);
    }
}
