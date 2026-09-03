<?php

namespace App\Http\Controllers;

use App\Domain\Forms\QuestionnairePackageService;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Organisation;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

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

    public function exportFile(Form $form, FormVersion $version, QuestionnairePackageService $packages)
    {
        $this->authorize('update', $form);
        abort_unless($version->form_id === $form->id, 404);
        $result = $packages->exportZip($form, $version);
        return response()->download($result['path'], $result['filename'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
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

    public function importFile(Request $request, Organisation $organisation, QuestionnairePackageService $packages)
    {
        abort_unless($request->user()->can('create', [Form::class, $organisation->id]), 403);
        $data = $request->validate(['package_file' => ['required', 'file', 'mimes:zip', 'max:51200']]);
        $uploaded = $data['package_file'];
        $root = rtrim((string) config('questionnaire_packages.root', base_path('questionnaires')), '\\/');
        $temporaryName = 'upload-'.Str::lower(str_replace('-', '', (string) Str::uuid()));
        $temporaryDirectory = $root.DIRECTORY_SEPARATOR.$temporaryName;
        $packageDirectory = null;
        $zip = new ZipArchive();
        $seen = [];
        $totalSize = 0;

        try {
            if ($zip->open($uploaded->getRealPath()) !== true || $zip->numFiles < 1 || $zip->numFiles > 1000) {
                $this->invalidUploadedPackage();
            }
            File::ensureDirectoryExists($temporaryDirectory);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $normalised = $this->safeArchiveEntry($name);
                if (isset($seen[$normalised])) $this->invalidUploadedPackage();
                $seen[$normalised] = true;
                $isDirectory = str_ends_with($normalised, '/');
                if ($isDirectory) continue;
                $stats = $zip->statIndex($index);
                $size = (int) ($stats['size'] ?? -1);
                if ($size < 0 || $size > 10 * 1024 * 1024 || ($totalSize += $size) > 50 * 1024 * 1024) $this->invalidUploadedPackage();
                $target = $temporaryDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalised);
                File::ensureDirectoryExists(dirname($target));
                $input = $zip->getStream($name);
                if (!is_resource($input)) $this->invalidUploadedPackage();
                $output = fopen($target, 'wb');
                if (!is_resource($output)) $this->invalidUploadedPackage();
                stream_copy_to_stream($input, $output);
                fclose($output); fclose($input);
                if (File::size($target) !== $size) $this->invalidUploadedPackage();
            }
            $zip->close();
            $manifestPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'manifest.json';
            if (!File::isFile($manifestPath)) $this->invalidUploadedPackage();
            $manifest = json_decode(File::get($manifestPath), true);
            $hash = is_array($manifest) ? ($manifest['content_hash'] ?? null) : null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) $this->invalidUploadedPackage();
            $packageName = $temporaryName.'--'.substr($hash, 0, 8);
            $packageDirectory = $root.DIRECTORY_SEPARATOR.$packageName;
            if (!File::moveDirectory($temporaryDirectory, $packageDirectory)) $this->invalidUploadedPackage();
            $form = $packages->import($packageName, $organisation, $request->user());
            return redirect()->route('forms.builder', $form)->with('success', __('messages.questionnaire_imported_as_draft'));
        } finally {
            if ($zip->status !== ZipArchive::ER_OK) $zip->close();
            if (File::isDirectory($temporaryDirectory)) File::deleteDirectory($temporaryDirectory);
            if ($packageDirectory && File::isDirectory($packageDirectory)) File::deleteDirectory($packageDirectory);
        }
    }

    private function safeArchiveEntry(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) $this->invalidUploadedPackage();
        $normalised = str_replace('\\', '/', $name);
        if (str_starts_with($normalised, '/') || preg_match('/^[A-Za-z]:/', $normalised)) $this->invalidUploadedPackage();
        $parts = explode('/', $normalised);
        if (in_array('..', $parts, true) || in_array('', $parts, true) && !str_ends_with($normalised, '/')) $this->invalidUploadedPackage();
        if ($normalised !== 'manifest.json' && !preg_match('#^assets/[A-Za-z0-9][A-Za-z0-9._-]*(?:/)?$#', $normalised)) $this->invalidUploadedPackage();
        return $normalised;
    }

    private function invalidUploadedPackage(): never
    {
        throw ValidationException::withMessages(['package_file' => __('messages.invalid_questionnaire_package')]);
    }

    public function parts(Form $form, FormVersion $version, QuestionnairePackageService $packages)
    {
        $this->authorize('update', $form);
        abort_unless($version->form_id === $form->id && $version->status === 'draft', 404);
        return view('questionnaires.parts', ['form' => $form, 'version' => $version, 'packages' => $packages->discoverForVersion($version)]);
    }

    public function importPart(Request $request, Form $form, FormVersion $version, QuestionnairePackageService $packages)
    {
        $this->authorize('update', $form);
        abort_unless($version->form_id === $form->id && $version->status === 'draft', 404);
        $data = $request->validate(['package_name' => ['required', 'string', 'max:255']]);
        $packages->importInto($data['package_name'], $version, $request->user());
        return redirect()->route('forms.builder', $form)->with('success', __('messages.questionnaire_part_imported'));
    }
}
