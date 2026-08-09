<?php

namespace App\Http\Controllers;

use App\Domain\Forms\QuestionnairePackageService;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Organisation;
use Illuminate\Http\Request;

class QuestionnairePackageController extends Controller
{
    public function export(Form $form, FormVersion $version, QuestionnairePackageService $packages)
    {
        $this->authorize('update', $form);
        abort_unless($version->form_id === $form->id, 404);
        $result = $packages->export($form, $version);
        $message = $result['duplicate'] ? __('messages.questionnaire_package_exists') : __('messages.questionnaire_package_saved');
        return back()->with('success', $message.' '.$result['relative_path']);
    }

    public function index(Organisation $organisation, QuestionnairePackageService $packages)
    {
        abort_unless(auth()->user()->can('create', [Form::class, $organisation->id]), 403);
        return view('questionnaires.index', ['organisation' => $organisation, 'packages' => $packages->discover($organisation)]);
    }

    public function import(Request $request, Organisation $organisation, QuestionnairePackageService $packages)
    {
        abort_unless($request->user()->can('create', [Form::class, $organisation->id]), 403);
        $data = $request->validate(['package_name' => ['required', 'string', 'max:255']]);
        $form = $packages->import($data['package_name'], $organisation, $request->user());
        return redirect()->route('forms.builder', $form)->with('success', __('messages.questionnaire_imported_as_draft'));
    }
}
