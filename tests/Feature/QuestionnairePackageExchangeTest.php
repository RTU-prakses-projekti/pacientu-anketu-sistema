<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Domain\Forms\QuestionnairePackageService;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\QuestionnairePackageImport;
use App\Models\QuestionnairePackagePartImport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionnairePackageExchangeTest extends TestCase
{
    use RefreshDatabase;

    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->packageRoot = storage_path('framework/testing/questionnaires-'.Str::uuid());
        config(['questionnaire_packages.root' => $this->packageRoot]);
    }

    protected function tearDown(): void
    {
        if (isset($this->packageRoot) && File::isDirectory($this->packageRoot)) File::deleteDirectory($this->packageRoot);
        parent::tearDown();
    }

    public function test_export_manifest_preserves_complete_portable_authoring_graph_and_translations(): void
    {
        [$creator, $organisation, $form, $version, $choice, $target] = $this->graph();
        $result = $this->actingAs($creator)->packages()->export($form, $version);
        $manifest = $this->manifest($result['package_name']);

        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame('universal_form_questionnaire', $manifest['package_type']);
        $this->assertSame(['en', 'lv', 'ru'], tap(array_keys($manifest['version']['translations']), fn (&$keys) => sort($keys)));
        $this->assertSame($version->sections()->count(), count($manifest['sections']));
        $portableChoice = collect($manifest['sections'])->flatMap(fn ($section) => $section['components'])->firstWhere('stable_key', $choice->stable_key);
        $this->assertSame($choice->stable_key, $portableChoice['stable_key']);
        $this->assertCount(5, $portableChoice['options']);
        $this->assertSame($choice->options()->orderBy('display_order')->pluck('stable_key')->all(), array_column($portableChoice['options'], 'stable_key'));
        $this->assertSame($choice->options()->orderBy('display_order')->pluck('value')->all(), array_column($portableChoice['options'], 'value'));
        $this->assertSame($choice->scoringRule->rules, $portableChoice['scoring_rule']['rules']);
        $this->assertSame($choice->validationRules->first()->parameters, $portableChoice['validation_rules'][0]['parameters']);
        $condition = $manifest['conditional_rules'][0];
        $this->assertSame($choice->stable_key, $condition['source_component_key']);
        $this->assertSame($target->stable_key, $condition['actions'][0]['target_component_key']);
        $this->assertArrayNotHasKey('source_component_id', $condition);
    }

    public function test_package_contains_no_identity_patient_submission_or_secret_data(): void
    {
        [$creator, $organisation, $form, $version] = $this->graph();
        PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $creator->id, 'slot_number' => 1, 'first_name' => 'Secret Patient', 'note' => 'Clinical secret']);
        $result = $this->actingAs($creator)->packages()->export($form, $version);
        $json = File::get($this->packageRoot.DIRECTORY_SEPARATOR.$result['package_name'].DIRECTORY_SEPARATOR.'manifest.json');
        foreach ([$creator->email, 'Secret Patient', 'Clinical secret', 'PAT-', 'patient_cases', 'form_submissions', 'access_token', 'organisation_id', 'created_by'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_identical_export_is_deterministic_and_does_not_create_duplicate_directory(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $first = $this->actingAs($creator)->packages()->export($form, $version);
        $second = $this->packages()->export($form->fresh(), $version->fresh());
        $this->assertSame($first['content_hash'], $second['content_hash']);
        $this->assertSame($first['package_name'], $second['package_name']);
        $this->assertFalse($first['duplicate']); $this->assertTrue($second['duplicate']);
        $this->assertCount(1, array_filter(File::directories($this->packageRoot), fn ($directory) => !str_starts_with(basename($directory), '.')));
    }

    public function test_authorized_local_export_ui_shows_and_exports_the_selected_version(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $this->actingAs($creator)->get(route('forms.show', $form))->assertOk()->assertSee(__('messages.export_to_git'))->assertSee('v1 (draft)');
        $response = $this->post(route('questionnaires.export', [$form, $version]))->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertCount(1, $this->packages()->discover());
    }

    public function test_browser_export_returns_a_valid_zip_with_the_existing_package_structure(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $response = $this->actingAs($creator)->post(route('questionnaires.export-file', [$form, $version]));
        $response->assertDownload($form->slug.'--'.substr($this->packages()->manifest($form, $version)['content_hash'], 0, 8).'.zip');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(['manifest.json'], [$zip->getNameIndex(0)]);
        $this->assertNotFalse($zip->getFromName('manifest.json'));
        $zip->close();
    }

    public function test_production_browser_export_downloads_a_zip_without_git_filesystem_write_and_includes_assets(): void
    {
        Storage::fake('local');
        [$creator, $organisation, $form, $version] = $this->graph();
        Storage::disk('local')->put('attachments/production.png', 'production-asset');
        $attachment = Attachment::create(['organisation_id' => $organisation->id, 'attachable_type' => $version->getMorphClass(), 'attachable_id' => $version->id,
            'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => 'attachments/production.png', 'original_name' => 'production.png',
            'mime_type' => 'image/png', 'size' => 15, 'sha256' => hash('sha256', 'production-asset'), 'status' => 'ready']);
        app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'image', 'label' => 'Production asset', 'settings' => ['attachment_id' => $attachment->id], 'options' => []]);
        $original = app()->environment(); app()->detectEnvironment(fn () => 'production');
        try {
            $response = $this->withSession(['_token' => 'test-token'])->actingAs($creator)->post(route('questionnaires.export-file', [$form, $version]), ['_token' => 'test-token']);
            $response->assertDownload();
            $path = $response->baseResponse->getFile()->getPathname();
            $zip = new \ZipArchive(); $this->assertTrue($zip->open($path) === true);
            $this->assertNotFalse($zip->getFromName('manifest.json'));
            $names = []; for ($index = 0; $index < $zip->numFiles; $index++) $names[] = $zip->getNameIndex($index);
            $this->assertTrue(collect($names)->contains(fn ($name) => str_starts_with($name, 'assets/')));
            $zip->close();
            $this->assertFalse(File::isDirectory($this->packageRoot));
            File::delete($path);
        } finally { app()->detectEnvironment(fn () => $original); }
    }

    public function test_production_git_filesystem_export_remains_denied(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $original = app()->environment(); app()->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(AuthorizationException::class);
            $this->packages()->export($form, $version);
        } finally { app()->detectEnvironment(fn () => $original); }
    }

    public function test_unauthorised_user_cannot_download_questionnaire_zip(): void
    {
        [$creator, $organisation, $form, $version] = $this->graph();
        [$unauthorised] = $this->member('doctor', $organisation);
        $this->actingAs($unauthorised)->post(route('questionnaires.export-file', [$form, $version]))->assertForbidden();
    }

    public function test_browser_import_of_exported_zip_creates_a_draft(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $export = $this->packages()->export($form, $version);
        $zipPath = $this->zipPackage($export['package_name']);
        $target = $this->organisation();
        $membership = OrganisationMembership::create(['organisation_id' => $target->id, 'user_id' => $creator->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', 'form_creator')->firstOrFail());
        $response = $this->actingAs($creator)->post(route('questionnaires.import-file', $target), [
            'package_file' => new UploadedFile($zipPath, 'questionnaire.zip', 'application/zip', null, true),
        ]);
        $response->assertRedirect();
        $imported = Form::where('organisation_id', $target->id)->firstOrFail();
        $this->assertSame('draft', $imported->status);
        $this->assertSame('draft', $imported->versions()->firstOrFail()->status);
        File::delete($zipPath);
    }

    public function test_invalid_and_traversal_zip_uploads_are_rejected_without_import(): void
    {
        [$creator, $organisation] = $this->member('form_creator', $this->organisation());
        $before = Form::count();
        $invalidPath = storage_path('framework/invalid-questionnaire.zip');
        File::put($invalidPath, 'not a zip');
        $this->actingAs($creator)->post(route('questionnaires.import-file', $organisation), [
            'package_file' => new UploadedFile($invalidPath, 'invalid.zip', 'application/zip', null, true),
        ])->assertSessionHasErrors('package_file');
        File::delete($invalidPath);

        $traversalPath = storage_path('framework/traversal-questionnaire.zip');
        $zip = new \ZipArchive(); $zip->open($traversalPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE); $zip->addFromString('../manifest.json', '{}'); $zip->close();
        $this->actingAs($creator)->post(route('questionnaires.import-file', $organisation), [
            'package_file' => new UploadedFile($traversalPath, 'traversal.zip', 'application/zip', null, true),
        ])->assertSessionHasErrors('package_file');
        File::delete($traversalPath);
        $this->assertSame($before, Form::count());
    }

    public function test_browser_file_import_requires_questionnaire_authoring_access(): void
    {
        $organisation = $this->organisation(); [$unauthorised] = $this->member('doctor', $organisation);
        $file = UploadedFile::fake()->create('questionnaire.zip', 1, 'application/zip');
        $this->actingAs($unauthorised)->post(route('questionnaires.import-file', $organisation), ['package_file' => $file])->assertForbidden();
    }

    public function test_attachment_exports_with_hash_and_import_remaps_it_to_private_storage(): void
    {
        Storage::fake('local');
        [$creator, $organisation, $form, $version] = $this->graph();
        Storage::disk('local')->put('attachments/source.png', 'portable-image');
        $attachment = Attachment::create(['organisation_id' => $organisation->id, 'attachable_type' => $version->getMorphClass(), 'attachable_id' => $version->id,
            'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => 'attachments/source.png', 'original_name' => 'diagram.png',
            'mime_type' => 'image/png', 'size' => strlen('portable-image'), 'sha256' => hash('sha256', 'portable-image'), 'status' => 'ready']);
        $image = app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'image', 'label' => 'Diagram', 'settings' => ['attachment_id' => $attachment->id], 'options' => []]);
        $export = $this->actingAs($creator)->packages()->export($form, $version->fresh()); $manifest = $this->manifest($export['package_name']); $validManifest = $manifest;
        $this->assertCount(1, $manifest['attachments']);
        $asset = $manifest['attachments'][0];
        $this->assertSame(hash('sha256', 'portable-image'), $asset['sha256']);
        $this->assertFileExists($this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $asset['asset_path']));

        $target = $this->organisation();
        $imported = $this->packages()->import($export['package_name'], $target, $creator);
        $importedVersion = $imported->versions()->firstOrFail(); $importedImage = $importedVersion->components()->where('stable_key', $image->stable_key)->firstOrFail();
        $this->assertNotSame($attachment->id, (int) $importedImage->settings['attachment_id']);
        $newAttachment = $importedVersion->attachments()->findOrFail($importedImage->settings['attachment_id']);
        $this->assertSame($attachment->sha256, $newAttachment->sha256); Storage::disk('local')->assertExists($newAttachment->storage_path);
    }

    public function test_roundtrip_import_creates_equivalent_new_draft_with_remapped_conditions(): void
    {
        [$creator, , $form, $version, $choice, $target] = $this->graph(); $sourceManifest = $this->packages()->manifest($form, $version);
        $export = $this->actingAs($creator)->packages()->export($form, $version); $targetOrganisation = $this->organisation();
        $imported = $this->packages()->import($export['package_name'], $targetOrganisation, $creator);
        $importedVersion = $imported->versions()->firstOrFail();
        $this->assertSame('draft', $imported->status); $this->assertSame('draft', $importedVersion->status); $this->assertNull($importedVersion->published_at);
        $this->assertNotSame($form->id, $imported->id); $this->assertNotSame($version->id, $importedVersion->id);
        $this->assertSame($version->sections()->count(), $importedVersion->sections()->count());
        $this->assertSame($version->components()->count(), $importedVersion->components()->count());
        $importedChoice = $importedVersion->components()->where('stable_key', $choice->stable_key)->firstOrFail();
        $this->assertNotSame($choice->id, $importedChoice->id);
        $this->assertEquals($choice->translations, $importedChoice->translations);
        $this->assertSame($choice->options()->pluck('value')->all(), $importedChoice->options()->pluck('value')->all());
        $this->assertSame($choice->scoringRule->rules, $importedChoice->scoringRule->rules);
        $importedCondition = $importedVersion->conditionalRules()->with('actions')->firstOrFail();
        $this->assertSame($importedChoice->id, $importedCondition->source_component_id);
        $this->assertSame($importedVersion->components()->where('stable_key', $target->stable_key)->value('id'), $importedCondition->actions->first()->target_component_id);
        $roundtripManifest = $this->packages()->manifest($imported, $importedVersion);
        $this->assertSame($sourceManifest['content_hash'], $roundtripManifest['content_hash']);
    }

    public function test_invalid_or_malicious_asset_package_is_rejected_without_partial_import(): void
    {
        Storage::fake('local');
        [$creator, $organisation, $form, $version] = $this->graph();
        Storage::disk('local')->put('attachments/source.txt', 'safe');
        $attachment = Attachment::create(['organisation_id' => $organisation->id, 'attachable_type' => $version->getMorphClass(), 'attachable_id' => $version->id,
            'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => 'attachments/source.txt', 'original_name' => 'safe.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'safe'), 'status' => 'ready']);
        app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'file_attachment', 'label' => 'File', 'settings' => ['attachment_id' => $attachment->id], 'options' => []]);
        $export = $this->actingAs($creator)->packages()->export($form, $version->fresh()); $manifest = $this->manifest($export['package_name']);
        $validManifest = $manifest;
        $manifest['attachments'][0]['asset_path'] = '../outside.txt'; $manifest['content_hash'] = $this->hash($manifest);
        File::put($this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest));
        $target = $this->organisation(); $before = Form::count();
        try { $this->packages()->import($export['package_name'], $target, $creator); $this->fail('Expected package validation failure'); }
        catch (ValidationException) { $this->assertSame($before, Form::count()); $this->assertSame(0, QuestionnairePackageImport::count()); }
        $validManifest['attachments'][0]['asset_path'] = 'C:/Windows/secret.txt'; $validManifest['content_hash'] = $this->hash($validManifest);
        File::put($this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.'manifest.json', json_encode($validManifest));
        try { $this->packages()->validatePackage($export['package_name']); $this->fail('Expected absolute path rejection'); }
        catch (ValidationException) { $this->assertTrue(true); }
    }

    public function test_modified_asset_content_fails_sha256_validation(): void
    {
        Storage::fake('local');
        [$creator, $organisation, $form, $version] = $this->graph(); Storage::disk('local')->put('attachments/source.txt', 'original');
        Attachment::create(['organisation_id' => $organisation->id, 'attachable_type' => $version->getMorphClass(), 'attachable_id' => $version->id,
            'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => 'attachments/source.txt', 'original_name' => 'evidence.txt',
            'mime_type' => 'text/plain', 'size' => 8, 'sha256' => hash('sha256', 'original'), 'status' => 'ready']);
        $export = $this->actingAs($creator)->packages()->export($form, $version); $manifest = $this->manifest($export['package_name']);
        $assetPath = $this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $manifest['attachments'][0]['asset_path']);
        File::put($assetPath, 'tampered');
        $this->expectException(ValidationException::class); $this->packages()->validatePackage($export['package_name']);
    }

    public function test_tampered_asset_hash_and_unknown_sensitive_manifest_field_are_rejected(): void
    {
        [$creator, , $form, $version] = $this->graph(); $export = $this->actingAs($creator)->packages()->export($form, $version);
        $manifestPath = $this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = $this->manifest($export['package_name']); $manifest['patient_data'] = ['name' => 'forbidden']; $manifest['content_hash'] = $this->hash($manifest);
        File::put($manifestPath, json_encode($manifest));
        $this->expectException(ValidationException::class); $this->packages()->validatePackage($export['package_name']);
    }

    public function test_duplicate_import_is_prevented_and_reported_in_import_ui(): void
    {
        [$creator, $target, $form, $version] = $this->graph(); $export = $this->actingAs($creator)->packages()->export($form, $version);
        $this->packages()->import($export['package_name'], $target, $creator);
        $this->assertTrue($this->packages()->discover($target)[0]['duplicate']);
        $this->actingAs($creator)->get(route('questionnaires.index', $target))->assertOk()->assertSee(__('messages.questionnaire_already_imported'));
        $this->expectException(ValidationException::class); $this->packages()->import($export['package_name'], $target, $creator);
    }

    public function test_package_can_be_appended_to_existing_draft_with_collisions_graph_and_provenance_preserved(): void
    {
        Storage::fake('local');
        [$creator, $organisation, $sourceForm, $sourceVersion, $sourceChoice, $sourceTarget] = $this->graph();
        $authoring = app(FormAuthoringService::class);
        $second = $authoring->addSection($sourceVersion, 'Otrā importējamā sadaļa');
        Storage::disk('local')->put('attachments/part.txt', 'part-asset');
        $attachment = Attachment::create(['organisation_id' => $organisation->id, 'attachable_type' => $sourceVersion->getMorphClass(), 'attachable_id' => $sourceVersion->id,
            'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => 'attachments/part.txt', 'original_name' => 'part.txt', 'mime_type' => 'text/plain',
            'size' => strlen('part-asset'), 'sha256' => hash('sha256', 'part-asset'), 'status' => 'ready']);
        $authoring->addComponent($sourceVersion, $second, ['type' => 'file_attachment', 'label' => 'Part file', 'settings' => ['attachment_id' => $attachment->id], 'options' => []]);
        $export = $this->actingAs($creator)->packages()->export($sourceForm, $sourceVersion->fresh());

        $targetForm = $authoring->create($organisation->id, $creator, 'Target form', 'blank');
        $targetVersion = $targetForm->versions()->firstOrFail();
        $existingSection = $targetVersion->sections()->firstOrFail();
        $existingSection->update(['stable_key' => $sourceVersion->sections()->first()->stable_key]);
        $existingComponent = $authoring->addComponent($targetVersion, $existingSection, ['type' => 'short_text', 'label' => 'Existing content', 'options' => []]);
        $existingComponent->update(['stable_key' => $sourceChoice->stable_key]);
        $existingLastOrder = $existingSection->display_order;

        $this->actingAs($creator)->get(route('forms.builder', $targetForm))->assertOk()->assertSee(__('messages.add_questionnaire_part_from_git'));
        $this->get(route('forms.show', $targetForm))->assertOk()->assertSee(__('messages.add_questionnaire_part_from_git'));
        $this->get(route('questionnaires.parts', [$targetForm, $targetVersion]))->assertOk()->assertSee($export['package_name'])->assertSee(__('messages.import_as_next_part'));

        $imported = $this->packages()->importInto($export['package_name'], $targetVersion, $creator);
        $this->assertSame(3, $imported->sections()->count());
        $this->assertDatabaseHas('form_components', ['id' => $existingComponent->id, 'label' => 'Existing content']);
        $this->assertSame($existingLastOrder + 1, $imported->sections()->where('id', '!=', $existingSection->id)->min('display_order'));
        $importedChoice = $imported->components()->where('label', $sourceChoice->label)->where('id', '!=', $existingComponent->id)->firstOrFail();
        $this->assertNotSame($sourceChoice->stable_key, $importedChoice->stable_key);
        $this->assertSame($sourceChoice->options()->orderBy('display_order')->pluck('value')->all(), $importedChoice->options()->orderBy('display_order')->pluck('value')->all());
        $this->assertSame($sourceChoice->scoringRule->rules, $importedChoice->scoringRule->rules);
        $condition = $imported->conditionalRules()->with('actions')->firstOrFail();
        $this->assertSame($importedChoice->id, $condition->source_component_id);
        $this->assertSame($imported->components()->where('label', $sourceTarget->label)->value('id'), $condition->actions->first()->target_component_id);
        $importedFile = $imported->components()->where('label', 'Part file')->firstOrFail();
        $this->assertNotNull(data_get($importedFile->settings, 'attachment_id'));
        Storage::disk('local')->assertExists($imported->attachments()->findOrFail(data_get($importedFile->settings, 'attachment_id'))->storage_path);
        $this->assertDatabaseHas('questionnaire_package_part_imports', ['form_version_id' => $targetVersion->id, 'content_hash' => $export['content_hash']]);

        try { $this->packages()->importInto($export['package_name'], $targetVersion, $creator); $this->fail('Expected duplicate part guard'); }
        catch (ValidationException) { $this->assertSame(1, QuestionnairePackagePartImport::where('form_version_id', $targetVersion->id)->count()); }
    }

    public function test_part_import_rejects_invalid_published_and_unauthorised_targets_without_partial_graph(): void
    {
        [$creator, $organisation, $sourceForm, $sourceVersion] = $this->graph();
        $export = $this->actingAs($creator)->packages()->export($sourceForm, $sourceVersion);
        $authoring = app(FormAuthoringService::class);
        $targetForm = $authoring->create($organisation->id, $creator, 'Part target', 'blank');
        $targetVersion = $targetForm->versions()->firstOrFail();
        $beforeSections = $targetVersion->sections()->count();

        [$respondent] = $this->member('respondent', $organisation);
        $this->actingAs($respondent)->get(route('questionnaires.parts', [$targetForm, $targetVersion]))->assertForbidden();
        $this->actingAs($respondent)->post(route('questionnaires.import-part', [$targetForm, $targetVersion]), ['package_name' => $export['package_name']])->assertForbidden();
        $this->assertSame($beforeSections, $targetVersion->sections()->count());

        $otherOrganisation = $this->organisation();
        [$otherCreator] = $this->member('form_creator', $otherOrganisation);
        $otherForm = $authoring->create($otherOrganisation->id, $otherCreator, 'Cross organisation target', 'blank');
        $this->actingAs($creator)->get(route('questionnaires.parts', [$otherForm, $otherForm->versions()->firstOrFail()]))->assertForbidden();

        $manifest = $this->manifest($export['package_name']);
        $manifest['conditional_rules'][0]['actions'][0]['target_component_key'] = null;
        $manifest['content_hash'] = $this->hash($manifest);
        File::put($this->packageRoot.DIRECTORY_SEPARATOR.$export['package_name'].DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest));
        $beforeComponents = $targetVersion->components()->count();
        try { $this->packages()->importInto($export['package_name'], $targetVersion, $creator); $this->fail('Expected invalid package rejection'); }
        catch (ValidationException) { $this->assertSame($beforeSections, $targetVersion->sections()->count()); $this->assertSame($beforeComponents, $targetVersion->components()->count()); }

        $published = $authoring->publish($targetVersion);
        try { $this->packages()->importInto($export['package_name'], $published, $creator); $this->fail('Expected immutable version rejection'); }
        catch (ValidationException) { $this->assertSame($beforeSections, $published->sections()->count()); }
    }

    public function test_import_requires_forms_create_and_export_is_denied_in_production(): void
    {
        [$creator, $organisation, $form, $version] = $this->graph();
        [$respondent] = $this->member('respondent', $organisation);
        $this->actingAs($respondent)->get(route('questionnaires.index', $organisation))->assertForbidden();
        $this->actingAs($respondent)->post(route('questionnaires.import', $organisation), ['package_name' => 'anything--12345678'])->assertForbidden();
        $original = app()->environment(); app()->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
            $this->packages()->export($form, $version);
        }
        finally { app()->detectEnvironment(fn () => $original); }
    }

    public function test_cli_lists_and_validates_git_packages(): void
    {
        [$creator, , $form, $version] = $this->graph(); $export = $this->actingAs($creator)->packages()->export($form, $version);
        $this->artisan('questionnaires:list')->expectsOutputToContain($export['package_name'])->assertSuccessful();
        $this->artisan('questionnaires:validate')->expectsOutputToContain('VALID '.$export['package_name'])->assertSuccessful();
    }

    public function test_targetless_conditional_action_is_rejected_by_builder_publish_and_export(): void
    {
        [$creator, , $form, $version] = $this->graph();
        $version->conditionalRules()->firstOrFail()->actions()->firstOrFail()->update(['target_component_id' => null, 'target_section_id' => null]);

        try { app(FormAuthoringService::class)->publish($version); $this->fail('Expected publish validation failure'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('conditions', $exception->errors()); }
        try { $this->packages()->manifest($form, $version); $this->fail('Expected export validation failure'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('conditional_actions', $exception->errors()); }

        $before = $version->conditionalRules()->count();
        $this->actingAs($creator)->post(route('builder.conditions.store', $form), [
            'source_component_id' => $version->components()->firstOrFail()->id, 'operator' => 'is_answered',
            'action' => 'show_component', 'target_component_id' => '', 'target_section_id' => '',
        ])->assertSessionHasErrors('action');
        $this->assertSame($before, $version->conditionalRules()->count());
    }

    private function graph(): array
    {
        $organisation = $this->organisation(); [$creator] = $this->member('form_creator', $organisation);
        $authoring = app(FormAuthoringService::class); $form = $authoring->create($organisation->id, $creator, 'Portatīvā anketa', 'blank');
        $form->update(['translations' => ['lv' => ['name' => 'LV anketa'], 'en' => ['name' => 'EN form'], 'ru' => ['name' => 'RU форма']]]);
        $version = $form->versions()->firstOrFail(); $version->update(['translations' => ['lv' => ['title' => 'LV virsraksts'], 'en' => ['title' => 'EN title'], 'ru' => ['title' => 'RU заголовок']]]);
        $section = $version->sections()->firstOrFail(); $section->update(['translations' => ['lv' => ['title' => 'LV sadaļa'], 'en' => ['title' => 'EN section'], 'ru' => ['title' => 'RU раздел']]]);
        $choice = $authoring->addComponent($version, $section, ['type' => 'single_choice', 'is_required' => true, 'max_points' => 2,
            'translations' => ['lv' => ['label' => 'LV izvēle'], 'en' => ['label' => 'EN choice'], 'ru' => ['label' => 'RU выбор']],
            'options' => [['translations' => ['lv' => ['label' => 'Jā'], 'en' => ['label' => 'Yes'], 'ru' => ['label' => 'Да']]], ['translations' => ['lv' => ['label' => 'Nē'], 'en' => ['label' => 'No'], 'ru' => ['label' => 'Нет']]], ['translations' => ['lv' => ['label' => 'Varbūt'], 'en' => ['label' => 'Maybe'], 'ru' => ['label' => 'Возможно']]], ['translations' => ['lv' => ['label' => 'Nezinu'], 'en' => ['label' => 'Unknown'], 'ru' => ['label' => 'Не знаю']]], ['translations' => ['lv' => ['label' => 'Cits'], 'en' => ['label' => 'Other'], 'ru' => ['label' => 'Другое']]]],
            'scoring_strategy' => 'single_choice', 'scoring_rules' => []]);
        $choice->scoringRule()->update(['rules' => ['correct' => $choice->options()->first()->value]]);
        $choice->validationRules()->create(['rule_type' => 'required', 'display_order' => 1, 'parameters' => ['strict' => true], 'message_translations' => ['lv' => ['message' => 'Obligāts'], 'en' => ['message' => 'Required'], 'ru' => ['message' => 'Обязательно']]]);
        $target = $authoring->addComponent($version, $section, ['type' => 'short_text', 'label' => 'Target', 'options' => []]);
        $rule = $version->conditionalRules()->create(['source_component_id' => $choice->id, 'operator' => 'equals', 'comparison_value' => ['value' => $choice->options()->first()->value], 'priority' => 1]);
        $rule->actions()->create(['action' => 'show_component', 'target_component_id' => $target->id]);
        return [$creator, $organisation, $form, $version->fresh(), $choice->fresh(['options','scoringRule','validationRules']), $target->fresh()];
    }

    private function packages(): QuestionnairePackageService { return app(QuestionnairePackageService::class); }
    private function manifest(string $packageName): array { return json_decode(File::get($this->packageRoot.DIRECTORY_SEPARATOR.$packageName.DIRECTORY_SEPARATOR.'manifest.json'), true, 512, JSON_THROW_ON_ERROR); }
    private function zipPackage(string $packageName): string
    {
        $path = storage_path('framework/'.$packageName.'.zip'); $zip = new \ZipArchive(); $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach (File::allFiles($this->packageRoot.DIRECTORY_SEPARATOR.$packageName) as $file) $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', Str::after($file->getPathname(), $this->packageRoot.DIRECTORY_SEPARATOR.$packageName.DIRECTORY_SEPARATOR)));
        $zip->close(); return $path;
    }
    private function organisation(): Organisation { return Organisation::create(['name' => Str::random(8), 'slug' => Str::lower(Str::random(10)), 'is_active' => true]); }
    private function member(string $role, Organisation $organisation): array
    {
        $user = User::factory()->create(['is_active' => true]); $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $role === 'respondent' ? 'doctor' : $role)->firstOrFail()); return [$user, $membership];
    }
    private function hash(array $manifest): string { unset($manifest['content_hash']); return hash('sha256', json_encode($this->canonicalize($manifest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); }
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item); return $value;
    }
}
