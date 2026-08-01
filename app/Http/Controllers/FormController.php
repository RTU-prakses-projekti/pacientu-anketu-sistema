<?php

namespace App\Http\Controllers;

use App\Domain\Forms\FormAuthoringService;
use App\Http\Requests\StoreFormRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Organisation;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function index(Organisation $organisation) { $this->authorize('view',$organisation); return view('forms.index',['organisation'=>$organisation,'forms'=>$organisation->forms()->withCount(['versions','publications'])->latest()->get()]); }
    public function create(Organisation $organisation) { abort_unless(auth()->user()->can('create',[Form::class,$organisation->id]),403); return view('forms.create',compact('organisation')); }
    public function store(StoreFormRequest $request, FormAuthoringService $service) { $form=$service->create((int)$request->organisation_id,$request->user(),$request->name,$request->preset); return redirect()->route('forms.builder',$form)->with('success',__('messages.form_created')); }
    public function show(Form $form) { $this->authorize('view',$form); return view('forms.show',['form'=>$form->load('versions','publications.formVersion')]); }
    public function update(Request $request,Form $form){$this->authorize('update',$form);$data=$request->validate(['name'=>'required|string|max:255','translations'=>'nullable|array']);$form->update($data);return back()->with('success',__('messages.saved'));}
    public function publish(Form $form, FormVersion $version, FormAuthoringService $service) { $this->authorize('publish',$form); abort_unless($version->form_id===$form->id,404); $service->publish($version); return redirect()->route('forms.show',$form)->with('success',__('messages.published')); }
    public function newDraft(Form $form, FormVersion $version, FormAuthoringService $service) { $this->authorize('update',$form); abort_unless($version->form_id===$form->id,404); $service->createDraftFrom($version,auth()->user()); return redirect()->route('forms.builder',$form)->with('success',__('messages.draft_created')); }
    public function duplicate(Form $form, FormAuthoringService $service) { $this->authorize('view',$form); abort_unless(auth()->user()->can('create',[Form::class,$form->organisation_id]),403); $copy=$service->duplicate($form,auth()->user()); return redirect()->route('forms.builder',$copy)->with('success',__('messages.duplicated')); }
    public function archive(Form $form, FormAuthoringService $service) { $this->authorize('archive',$form); $service->archive($form); return back()->with('success',__('messages.archived')); }
}
