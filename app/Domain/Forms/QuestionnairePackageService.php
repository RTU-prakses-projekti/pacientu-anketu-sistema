<?php

namespace App\Domain\Forms;

use App\Domain\Audit\AuditService;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Organisation;
use App\Models\QuestionnairePackageImport;
use App\Models\QuestionnairePackagePartImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class QuestionnairePackageService
{
    public const SCHEMA_VERSION = 1;
    public const PACKAGE_TYPE = 'universal_form_questionnaire';

    public function __construct(
        private ComponentRegistry $registry,
        private ScoringRuleValidator $scoring,
        private AuditService $audit,
    ) {}

    public function export(Form $form, FormVersion $version): array
    {
        if (!in_array(app()->environment(), config('questionnaire_packages.write_environments', []), true)) {
            throw new AuthorizationException(__('messages.questionnaire_export_local_only'));
        }
        abort_unless($version->form_id === $form->id, 404);
        $manifest = $this->manifest($form, $version);
        $hash = $manifest['content_hash'];
        $name = $this->packageName($form, $hash);
        $root = $this->root();
        $destination = $root.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($root);

        if (File::isDirectory($destination)) {
            $existing = $this->validateDirectory($destination);
            if (!hash_equals($hash, $existing['content_hash'])) $this->invalid('package');
            return ['package_name' => $name, 'relative_path' => 'questionnaires/'.$name, 'content_hash' => $hash, 'duplicate' => true];
        }

        $temporary = $root.DIRECTORY_SEPARATOR.'.tmp-'.Str::uuid();
        File::ensureDirectoryExists($temporary);
        try {
            foreach ($manifest['attachments'] as $attachment) {
                $source = $version->attachments->firstWhere('sha256', $attachment['sha256']);
                if (!$source || !Storage::disk($source->disk)->exists($source->storage_path)) $this->invalid('attachments');
                $sourcePath = Storage::disk($source->disk)->path($source->storage_path);
                if (!hash_equals($attachment['sha256'], hash_file('sha256', $sourcePath))) $this->invalid('attachments');
                $target = $temporary.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $attachment['asset_path']);
                File::ensureDirectoryExists(dirname($target));
                File::copy($sourcePath, $target);
            }
            File::put($temporary.DIRECTORY_SEPARATOR.'manifest.json', $this->prettyJson($manifest).PHP_EOL);
            if (!File::moveDirectory($temporary, $destination)) $this->invalid('package');
        } catch (Throwable $exception) {
            if (File::isDirectory($temporary)) File::deleteDirectory($temporary);
            throw $exception;
        }

        $this->audit->record('questionnaire_package.exported', $version, $form->organisation_id, ['content_hash' => $hash, 'package_name' => $name]);
        return ['package_name' => $name, 'relative_path' => 'questionnaires/'.$name, 'content_hash' => $hash, 'duplicate' => false];
    }

    /**
     * Build a portable browser download without writing a Git package directory.
     * The manifest and asset serialization remains the same as the Git export.
     */
    public function exportZip(Form $form, FormVersion $version): array
    {
        abort_unless($version->form_id === $form->id, 404);
        $manifest = $this->manifest($form, $version);
        $hash = $manifest['content_hash'];
        $name = $this->packageName($form, $hash);
        $directory = storage_path('framework/questionnaire-downloads');
        $path = $directory.DIRECTORY_SEPARATOR.$name.'.zip';
        File::ensureDirectoryExists($directory);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) $this->invalid('package');
        try {
            if (!$zip->addFromString('manifest.json', $this->prettyJson($manifest).PHP_EOL)) $this->invalid('package');
            foreach ($manifest['attachments'] as $attachment) {
                $source = $version->attachments->firstWhere('sha256', $attachment['sha256']);
                if (!$source || !Storage::disk($source->disk)->exists($source->storage_path)) $this->invalid('attachments');
                $sourcePath = Storage::disk($source->disk)->path($source->storage_path);
                if (!hash_equals($attachment['sha256'], hash_file('sha256', $sourcePath))) $this->invalid('attachments');
                if (!$zip->addFile($sourcePath, $attachment['asset_path'])) $this->invalid('attachments');
            }
            $zip->close();
            $this->audit->record('questionnaire_package.downloaded', $version, $form->organisation_id, ['content_hash' => $hash, 'package_name' => $name]);
            return ['path' => $path, 'filename' => $name.'.zip', 'content_hash' => $hash];
        } catch (Throwable $exception) {
            $zip->close();
            if (File::exists($path)) File::delete($path);
            throw $exception;
        }
    }

    public function discover(?Organisation $organisation = null, bool $includeInvalid = false): array
    {
        $root = $this->root();
        if (!File::isDirectory($root)) return [];
        $result = [];
        foreach (File::directories($root) as $directory) {
            $name = basename($directory);
            if (str_starts_with($name, '.')) continue;
            try {
                $manifest = $this->validateDirectory($directory);
                $result[] = $this->summary($name, $manifest, $organisation);
            } catch (Throwable $exception) {
                if ($includeInvalid) $result[] = ['package_name' => $name, 'valid' => false, 'error' => $exception->getMessage()];
            }
        }
        usort($result, fn ($a, $b) => strcmp($a['package_name'], $b['package_name']));
        return $result;
    }

    public function validatePackage(string $packageName): array
    {
        return $this->validateDirectory($this->packageDirectory($packageName));
    }

    public function discoverForVersion(FormVersion $version): array
    {
        return array_map(function (array $package) use ($version) {
            $package['part_duplicate'] = QuestionnairePackagePartImport::where('form_version_id', $version->id)
                ->where('content_hash', $package['content_hash'])->exists();
            return $package;
        }, $this->discover($version->form->organisation));
    }

    public function import(string $packageName, Organisation $organisation, User $creator): Form
    {
        $directory = $this->packageDirectory($packageName);
        $manifest = $this->validateDirectory($directory);
        $hash = $manifest['content_hash'];
        if (QuestionnairePackageImport::where('organisation_id', $organisation->id)->where('content_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['package' => __('messages.questionnaire_already_imported')]);
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($manifest, $directory, $packageName, $organisation, $creator, $hash, &$storedPaths) {
                $form = Form::create([
                    'organisation_id' => $organisation->id,
                    'created_by' => $creator->id,
                    'name' => $manifest['form']['name'],
                    'slug' => $this->uniqueSlug($organisation->id, $manifest['form']['name']),
                    'status' => 'draft',
                    'preset_key' => $manifest['form']['preset_key'],
                    'translations' => $manifest['form']['translations'],
                ]);
                $version = $form->versions()->create([
                    'version_number' => 1,
                    'status' => 'draft',
                    'title' => $manifest['version']['title'],
                    'description' => $manifest['version']['description'],
                    'settings' => $manifest['version']['settings'],
                    'translations' => $manifest['version']['translations'],
                    'created_by' => $creator->id,
                ]);

                $attachmentMap = [];
                foreach ($manifest['attachments'] as $portable) {
                    $absolute = $this->assetPath($directory, $portable['asset_path']);
                    $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($portable['original_name'], PATHINFO_EXTENSION)));
                    $storagePath = 'attachments/'.$organisation->id.'/'.Str::uuid().($extension ? '.'.$extension : '');
                    Storage::disk('local')->put($storagePath, File::get($absolute));
                    $storedPaths[] = $storagePath;
                    $attachment = Attachment::create([
                        'organisation_id' => $organisation->id,
                        'attachable_type' => $version->getMorphClass(),
                        'attachable_id' => $version->id,
                        'uploaded_by' => $creator->id,
                        'disk' => 'local',
                        'storage_path' => $storagePath,
                        'original_name' => $portable['original_name'],
                        'mime_type' => $portable['mime_type'],
                        'size' => $portable['size'],
                        'sha256' => $portable['sha256'],
                        'status' => 'ready',
                    ]);
                    $attachmentMap[$portable['ref']] = $attachment->id;
                }

                $sectionMap = [];
                $componentMap = [];
                foreach ($manifest['sections'] as $portableSection) {
                    $section = $version->sections()->create([
                        'stable_key' => $portableSection['stable_key'], 'title' => $portableSection['title'],
                        'description' => $portableSection['description'], 'display_order' => $portableSection['display_order'],
                        'visible' => $portableSection['visible'], 'translations' => $portableSection['translations'],
                    ]);
                    $sectionMap[$portableSection['stable_key']] = $section->id;
                    foreach ($portableSection['components'] as $portableComponent) {
                        $settings = $portableComponent['settings'];
                        if ($portableComponent['attachment_ref']) $settings['attachment_id'] = $attachmentMap[$portableComponent['attachment_ref']];
                        $component = $section->components()->create([
                            'form_version_id' => $version->id, 'stable_key' => $portableComponent['stable_key'], 'type' => $portableComponent['type'],
                            'label' => $portableComponent['label'], 'description' => $portableComponent['description'], 'help_text' => $portableComponent['help_text'],
                            'display_order' => $portableComponent['display_order'], 'is_required' => $portableComponent['is_required'], 'visible' => $portableComponent['visible'],
                            'max_points' => $portableComponent['max_points'], 'manual_grading' => $portableComponent['manual_grading'],
                            'settings' => $settings, 'translations' => $portableComponent['translations'],
                        ]);
                        $componentMap[$portableComponent['stable_key']] = $component->id;
                        foreach ($portableComponent['options'] as $option) $component->options()->create($option);
                        foreach ($portableComponent['validation_rules'] as $rule) $component->validationRules()->create($rule);
                        if ($portableComponent['scoring_rule']) $component->scoringRule()->create($portableComponent['scoring_rule']);
                    }
                }

                foreach ($manifest['conditional_rules'] as $portableRule) {
                    $rule = $version->conditionalRules()->create([
                        'source_component_id' => $componentMap[$portableRule['source_component_key']],
                        'operator' => $portableRule['operator'], 'comparison_value' => $portableRule['comparison_value'], 'priority' => $portableRule['priority'],
                    ]);
                    foreach ($portableRule['actions'] as $portableAction) {
                        $rule->actions()->create([
                            'action' => $portableAction['action'],
                            'target_component_id' => $portableAction['target_component_key'] ? $componentMap[$portableAction['target_component_key']] : null,
                            'target_section_id' => $portableAction['target_section_key'] ? $sectionMap[$portableAction['target_section_key']] : null,
                        ]);
                    }
                }

                QuestionnairePackageImport::create([
                    'organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $version->id,
                    'imported_by' => $creator->id, 'content_hash' => $hash, 'package_name' => $packageName,
                ]);
                $this->audit->record('questionnaire_package.imported', $version, $organisation->id, ['content_hash' => $hash, 'package_name' => $packageName]);
                return $form->fresh('versions.sections.components.options');
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function importInto(string $packageName, FormVersion $version, User $creator): FormVersion
    {
        $version->loadMissing('form');
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => __('messages.published_immutable')]);
        }

        $directory = $this->packageDirectory($packageName);
        $manifest = $this->validateDirectory($directory);
        $hash = $manifest['content_hash'];
        if (QuestionnairePackagePartImport::where('form_version_id', $version->id)->where('content_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['package' => __('messages.questionnaire_part_already_imported')]);
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($manifest, $directory, $packageName, $version, $creator, $hash, &$storedPaths) {
                $organisationId = $version->form->organisation_id;
                $attachmentMap = [];
                foreach ($manifest['attachments'] as $portable) {
                    $absolute = $this->assetPath($directory, $portable['asset_path']);
                    $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($portable['original_name'], PATHINFO_EXTENSION)));
                    $storagePath = 'attachments/'.$organisationId.'/'.Str::uuid().($extension ? '.'.$extension : '');
                    Storage::disk('local')->put($storagePath, File::get($absolute));
                    $storedPaths[] = $storagePath;
                    $attachment = Attachment::create([
                        'organisation_id' => $organisationId, 'attachable_type' => $version->getMorphClass(), 'attachable_id' => $version->id,
                        'uploaded_by' => $creator->id, 'disk' => 'local', 'storage_path' => $storagePath,
                        'original_name' => $portable['original_name'], 'mime_type' => $portable['mime_type'], 'size' => $portable['size'],
                        'sha256' => $portable['sha256'], 'status' => 'ready',
                    ]);
                    $attachmentMap[$portable['ref']] = $attachment->id;
                }

                $usedSectionKeys = $version->sections()->pluck('stable_key')->flip()->all();
                $usedComponentKeys = $version->components()->pluck('stable_key')->flip()->all();
                $nextSectionOrder = ((int) $version->sections()->max('display_order')) + 1;
                $nextConditionPriority = ((int) $version->conditionalRules()->max('priority')) + 1;
                $sectionMap = [];
                $componentMap = [];
                foreach ($manifest['sections'] as $sectionOffset => $portableSection) {
                    $sectionKey = $this->availablePortableKey($portableSection['stable_key'], $usedSectionKeys);
                    $section = $version->sections()->create([
                        'stable_key' => $sectionKey, 'title' => $portableSection['title'], 'description' => $portableSection['description'],
                        'display_order' => $nextSectionOrder + $sectionOffset, 'visible' => $portableSection['visible'], 'translations' => $portableSection['translations'],
                    ]);
                    $sectionMap[$portableSection['stable_key']] = $section->id;
                    foreach ($portableSection['components'] as $componentOffset => $portableComponent) {
                        $componentKey = $this->availablePortableKey($portableComponent['stable_key'], $usedComponentKeys);
                        $settings = $portableComponent['settings'];
                        if ($portableComponent['attachment_ref']) $settings['attachment_id'] = $attachmentMap[$portableComponent['attachment_ref']];
                        $component = $section->components()->create([
                            'form_version_id' => $version->id, 'stable_key' => $componentKey, 'type' => $portableComponent['type'],
                            'label' => $portableComponent['label'], 'description' => $portableComponent['description'], 'help_text' => $portableComponent['help_text'],
                            'display_order' => $componentOffset + 1, 'is_required' => $portableComponent['is_required'], 'visible' => $portableComponent['visible'],
                            'max_points' => $portableComponent['max_points'], 'manual_grading' => $portableComponent['manual_grading'],
                            'settings' => $settings, 'translations' => $portableComponent['translations'],
                        ]);
                        $componentMap[$portableComponent['stable_key']] = $component->id;
                        foreach ($portableComponent['options'] as $option) $component->options()->create($option);
                        foreach ($portableComponent['validation_rules'] as $rule) $component->validationRules()->create($rule);
                        if ($portableComponent['scoring_rule']) $component->scoringRule()->create($portableComponent['scoring_rule']);
                    }
                }

                foreach ($manifest['conditional_rules'] as $ruleOffset => $portableRule) {
                    $rule = $version->conditionalRules()->create([
                        'source_component_id' => $componentMap[$portableRule['source_component_key']],
                        'operator' => $portableRule['operator'], 'comparison_value' => $portableRule['comparison_value'],
                        'priority' => $nextConditionPriority + $ruleOffset,
                    ]);
                    foreach ($portableRule['actions'] as $portableAction) {
                        $rule->actions()->create([
                            'action' => $portableAction['action'],
                            'target_component_id' => $portableAction['target_component_key'] ? $componentMap[$portableAction['target_component_key']] : null,
                            'target_section_id' => $portableAction['target_section_key'] ? $sectionMap[$portableAction['target_section_key']] : null,
                        ]);
                    }
                }

                QuestionnairePackagePartImport::create([
                    'organisation_id' => $organisationId, 'form_id' => $version->form_id, 'form_version_id' => $version->id,
                    'imported_by' => $creator->id, 'content_hash' => $hash, 'package_name' => $packageName,
                ]);
                $this->audit->record('questionnaire_package.part_imported', $version, $organisationId, ['content_hash' => $hash, 'package_name' => $packageName]);
                return $version->fresh('sections.components.options');
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function manifest(Form $form, FormVersion $version): array
    {
        $version->load('attachments', 'sections.components.options', 'sections.components.validationRules', 'sections.components.scoringRule', 'conditionalRules.actions');
        $attachmentRefs = [];
        $attachments = [];
        foreach ($version->attachments->sortBy(fn ($item) => $item->sha256.'|'.$item->original_name) as $attachment) {
            $safeName = Str::limit(Str::slug(pathinfo($attachment->original_name, PATHINFO_FILENAME)) ?: 'asset', 60, '');
            $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($attachment->original_name, PATHINFO_EXTENSION)));
            $identity = hash('sha256', $this->canonicalJson(['sha256' => $attachment->sha256, 'original_name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size' => (int) $attachment->size]));
            $fileName = $identity.'-'.$safeName.($extension ? '.'.$extension : '');
            $ref = 'asset:'.$identity;
            $attachmentRefs[$attachment->id] = $ref;
            $attachments[$ref] = [
                'ref' => $ref, 'asset_path' => 'assets/'.$fileName, 'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type, 'size' => (int) $attachment->size, 'sha256' => $attachment->sha256,
            ];
        }

        $componentKeys = $version->sections->flatMap->components->keyBy('id')->map->stable_key;
        $sectionKeys = $version->sections->keyBy('id')->map->stable_key;
        foreach ($version->conditionalRules as $rule) {
            if (!isset($componentKeys[$rule->source_component_id])) $this->invalid('conditional_rules');
            foreach ($rule->actions as $action) {
                $componentAction = in_array($action->action, ['show_component', 'hide_component'], true);
                if (($componentAction && (!$action->target_component_id || $action->target_section_id || !isset($componentKeys[$action->target_component_id])))
                    || (!$componentAction && (!$action->target_section_id || $action->target_component_id || !isset($sectionKeys[$action->target_section_id])))
                    || $action->target_component_id === $rule->source_component_id) $this->invalid('conditional_actions');
            }
        }
        $sections = $version->sections->sortBy(fn ($section) => sprintf('%010d|%s', $section->display_order, $section->stable_key))->map(function ($section) use ($attachmentRefs) {
            return [
                'stable_key' => $section->stable_key, 'title' => $section->title, 'description' => $section->description,
                'display_order' => (int) $section->display_order, 'visible' => (bool) $section->visible, 'translations' => $section->translations,
                'components' => $section->components->sortBy(fn ($component) => sprintf('%010d|%s', $component->display_order, $component->stable_key))->map(function ($component) use ($attachmentRefs) {
                    $settings = $component->settings ?? [];
                    if (isset($settings['attachment_id']) && !isset($attachmentRefs[$settings['attachment_id']])) $this->invalid('attachments');
                    $attachmentRef = isset($settings['attachment_id']) ? $attachmentRefs[$settings['attachment_id']] : null;
                    unset($settings['attachment_id']);
                    return [
                        'stable_key' => $component->stable_key, 'type' => $component->type, 'label' => $component->label,
                        'description' => $component->description, 'help_text' => $component->help_text, 'display_order' => (int) $component->display_order,
                        'is_required' => (bool) $component->is_required, 'visible' => (bool) $component->visible,
                        'max_points' => (float) $component->max_points, 'manual_grading' => (bool) $component->manual_grading,
                        'settings' => $settings, 'translations' => $component->translations, 'attachment_ref' => $attachmentRef,
                        'options' => $component->options->sortBy(fn ($option) => sprintf('%010d|%s', $option->display_order, $option->stable_key))->map(fn ($option) => [
                            'stable_key' => $option->stable_key, 'label' => $option->label, 'value' => $option->value,
                            'display_order' => (int) $option->display_order, 'translations' => $option->translations,
                        ])->values()->all(),
                        'validation_rules' => $component->validationRules->sortBy(fn ($rule) => sprintf('%010d|%s|%s|%s', $rule->display_order, $rule->rule_type, $this->canonicalJson($rule->parameters ?? []), $this->canonicalJson($rule->message_translations ?? [])))->map(fn ($rule) => [
                            'rule_type' => $rule->rule_type, 'display_order' => (int) $rule->display_order,
                            'parameters' => $rule->parameters, 'message_translations' => $rule->message_translations,
                        ])->values()->all(),
                        'scoring_rule' => $component->scoringRule ? [
                            'strategy' => $component->scoringRule->strategy, 'max_points' => (float) $component->scoringRule->max_points,
                            'rules' => $component->scoringRule->rules,
                        ] : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $conditions = $version->conditionalRules->sortBy(function ($rule) use ($componentKeys, $sectionKeys) {
            $actions = $rule->actions->map(fn ($action) => implode('|', [
                $action->action, $action->target_component_id ? ($componentKeys[$action->target_component_id] ?? '') : '',
                $action->target_section_id ? ($sectionKeys[$action->target_section_id] ?? '') : '',
            ]))->sort()->values()->all();
            return sprintf('%010d|%s|%s|%s', $rule->priority, $componentKeys[$rule->source_component_id] ?? '', $rule->operator, $this->canonicalJson(['comparison' => $rule->comparison_value, 'actions' => $actions]));
        })->map(function ($rule) use ($componentKeys, $sectionKeys) {
            return [
                'source_component_key' => $componentKeys[$rule->source_component_id], 'operator' => $rule->operator,
                'comparison_value' => $rule->comparison_value, 'priority' => (int) $rule->priority,
                'actions' => $rule->actions->sortBy(fn ($action) => implode('|', [$action->action, $action->target_component_id ? $componentKeys[$action->target_component_id] : '', $action->target_section_id ? $sectionKeys[$action->target_section_id] : '']))->map(fn ($action) => [
                    'action' => $action->action,
                    'target_component_key' => $action->target_component_id ? $componentKeys[$action->target_component_id] : null,
                    'target_section_key' => $action->target_section_id ? $sectionKeys[$action->target_section_id] : null,
                ])->values()->all(),
            ];
        })->values()->all();

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_type' => self::PACKAGE_TYPE,
            'form' => ['name' => $form->name, 'preset_key' => $form->preset_key, 'translations' => $form->translations],
            'version' => ['title' => $version->title ?: $form->name, 'description' => $version->description, 'settings' => $version->settings ?? [], 'translations' => $version->translations],
            'sections' => $sections,
            'conditional_rules' => $conditions,
            'attachments' => array_values($attachments),
        ];
        $manifest['content_hash'] = hash('sha256', $this->canonicalJson($manifest));
        return $manifest;
    }

    private function validateDirectory(string $directory): array
    {
        $root = realpath($this->root());
        $real = realpath($directory);
        if (!$root || !$real || !($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR))) $this->invalid('package');
        $manifestPath = $real.DIRECTORY_SEPARATOR.'manifest.json';
        if (!File::isFile($manifestPath) || File::size($manifestPath) > 5 * 1024 * 1024) $this->invalid('manifest');
        try { $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $this->invalid('manifest'); }
        $this->validateManifest($manifest, $real);
        if (!str_ends_with(basename($real), '--'.substr($manifest['content_hash'], 0, 8))) $this->invalid('content_hash');
        return $manifest;
    }

    private function validateManifest(mixed $manifest, string $directory): void
    {
        if (!is_array($manifest)) $this->invalid('manifest');
        $this->exactKeys($manifest, ['schema_version','package_type','form','version','sections','conditional_rules','attachments','content_hash']);
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($manifest['package_type'] ?? null) !== self::PACKAGE_TYPE) $this->invalid('schema_version');
        if (!is_string($manifest['content_hash']) || !preg_match('/^[a-f0-9]{64}$/', $manifest['content_hash'])) $this->invalid('content_hash');
        $hashInput = $manifest; unset($hashInput['content_hash']);
        if (!hash_equals($manifest['content_hash'], hash('sha256', $this->canonicalJson($hashInput)))) $this->invalid('content_hash');

        $this->exactKeys($manifest['form'], ['name','preset_key','translations']);
        $this->exactKeys($manifest['version'], ['title','description','settings','translations']);
        if (!is_string($manifest['form']['name']) || trim($manifest['form']['name']) === '' || mb_strlen($manifest['form']['name']) > 255
            || !in_array($manifest['form']['preset_key'], ['blank','test','patient_questionnaire'], true)) $this->invalid('form');
        if (!is_string($manifest['version']['title']) || trim($manifest['version']['title']) === '' || mb_strlen($manifest['version']['title']) > 255
            || ($manifest['version']['description'] !== null && !is_string($manifest['version']['description'])) || !is_array($manifest['version']['settings'])) $this->invalid('version');
        $this->translations($manifest['form']['translations']); $this->translations($manifest['version']['translations']);
        if (!is_array($manifest['sections']) || !is_array($manifest['conditional_rules']) || !is_array($manifest['attachments'])) $this->invalid('manifest');

        $attachmentRefs = [];
        foreach ($manifest['attachments'] as $attachment) {
            $this->exactKeys($attachment, ['ref','asset_path','original_name','mime_type','size','sha256']);
            $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','text/csv','application/csv','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream'];
            $extension = strtolower(pathinfo($attachment['original_name'], PATHINFO_EXTENSION));
            if (!is_string($attachment['ref']) || !preg_match('/^asset:[a-f0-9]{64}$/', $attachment['ref']) || isset($attachmentRefs[$attachment['ref']]) || !is_string($attachment['original_name']) || mb_strlen($attachment['original_name']) > 255
                || basename($attachment['original_name']) !== $attachment['original_name'] || preg_match('/[\x00-\x1F\x7F]/', $attachment['original_name'])
                || !in_array($extension, ['jpg','jpeg','png','gif','webp','pdf','txt','csv','doc','docx'], true) || !in_array($attachment['mime_type'], $allowedMimes, true)
                || !is_int($attachment['size']) || $attachment['size'] < 0 || $attachment['size'] > 10 * 1024 * 1024
                || !preg_match('/^[a-f0-9]{64}$/', (string) $attachment['sha256'])) $this->invalid('attachments');
            $absolute = $this->assetPath($directory, $attachment['asset_path']);
            if (!hash_equals($attachment['sha256'], hash_file('sha256', $absolute)) || filesize($absolute) !== $attachment['size']) $this->invalid('attachments');
            $attachmentRefs[$attachment['ref']] = true;
        }

        $sectionKeys = []; $componentKeys = [];
        foreach ($manifest['sections'] as $section) {
            $this->exactKeys($section, ['stable_key','title','description','display_order','visible','translations','components']);
            if (!$this->portableKey($section['stable_key']) || isset($sectionKeys[$section['stable_key']]) || !is_string($section['title']) || mb_strlen($section['title']) > 255
                || ($section['description'] !== null && !is_string($section['description'])) || !is_int($section['display_order']) || $section['display_order'] < 0 || !is_bool($section['visible']) || !is_array($section['components'])) $this->invalid('sections');
            $sectionKeys[$section['stable_key']] = true; $this->translations($section['translations']);
            foreach ($section['components'] as $component) {
                $this->exactKeys($component, ['stable_key','type','label','description','help_text','display_order','is_required','visible','max_points','manual_grading','settings','translations','attachment_ref','options','validation_rules','scoring_rule']);
                if (!$this->portableKey($component['stable_key']) || isset($componentKeys[$component['stable_key']]) || !is_string($component['type']) || !is_string($component['label']) || mb_strlen($component['label']) > 255
                    || ($component['description'] !== null && !is_string($component['description'])) || ($component['help_text'] !== null && !is_string($component['help_text'])) || !is_int($component['display_order']) || $component['display_order'] < 0
                    || !is_bool($component['is_required']) || !is_bool($component['visible']) || !is_numeric($component['max_points']) || !is_bool($component['manual_grading']) || !is_array($component['settings'])
                    || !is_array($component['options']) || !is_array($component['validation_rules'])) $this->invalid('components');
                $this->registry->definition($component['type']);
                if (array_key_exists('attachment_id', $component['settings'])) $this->invalid('settings');
                if ($this->registry->filterSettings($component['type'], $component['settings']) !== $component['settings']) $this->invalid('settings');
                if ($component['attachment_ref'] !== null && (!in_array('attachment_id', $this->registry->allowedSettings($component['type']), true) || !isset($attachmentRefs[$component['attachment_ref']]))) $this->invalid('attachments');
                $componentKeys[$component['stable_key']] = $component; $this->translations($component['translations']);
                $optionValues = []; $optionKeys = [];
                foreach ($component['options'] as $option) {
                    $this->exactKeys($option, ['stable_key','label','value','display_order','translations']);
                    if (!$this->portableKey($option['stable_key']) || isset($optionKeys[$option['stable_key']]) || !is_string($option['label']) || mb_strlen($option['label']) > 255 || !is_string($option['value'])
                        || !Str::isUuid($option['value']) || isset($optionValues[$option['value']]) || !is_int($option['display_order']) || $option['display_order'] < 0) $this->invalid('options');
                    $optionKeys[$option['stable_key']] = true; $optionValues[$option['value']] = true; $this->translations($option['translations']);
                }
                foreach ($component['validation_rules'] as $rule) {
                    $this->exactKeys($rule, ['rule_type','display_order','parameters','message_translations']);
                    if (!is_string($rule['rule_type']) || !is_int($rule['display_order']) || ($rule['parameters'] !== null && !is_array($rule['parameters']))) $this->invalid('validation_rules');
                    $this->translations($rule['message_translations']);
                }
                $this->validatePortableScoring($component, array_keys($optionValues));
            }
        }

        $operators = ['equals','not_equals','contains','greater_than','less_than','is_answered','is_not_answered'];
        $actions = ['show_component','hide_component','show_section','hide_section'];
        foreach ($manifest['conditional_rules'] as $rule) {
            $this->exactKeys($rule, ['source_component_key','operator','comparison_value','priority','actions']);
            if (!isset($componentKeys[$rule['source_component_key']]) || !in_array($rule['operator'], $operators, true) || !is_int($rule['priority']) || !is_array($rule['actions'])) $this->invalid('conditional_rules');
            foreach ($rule['actions'] as $action) {
                $this->exactKeys($action, ['action','target_component_key','target_section_key']);
                if (!in_array($action['action'], $actions, true)) $this->invalid('conditional_actions');
                if ($action['target_component_key'] !== null && !isset($componentKeys[$action['target_component_key']])) $this->invalid('conditional_actions');
                if ($action['target_section_key'] !== null && !isset($sectionKeys[$action['target_section_key']])) $this->invalid('conditional_actions');
                $componentAction = in_array($action['action'], ['show_component','hide_component'], true);
                if ($componentAction && ($action['target_component_key'] === null || $action['target_section_key'] !== null)) $this->invalid('conditional_actions');
                if (!$componentAction && ($action['target_section_key'] === null || $action['target_component_key'] !== null)) $this->invalid('conditional_actions');
                if ($action['target_component_key'] === $rule['source_component_key']) $this->invalid('conditional_actions');
            }
        }
    }

    private function validatePortableScoring(array $component, array $optionValues): void
    {
        $scoring = $component['scoring_rule'];
        if ($scoring === null) return;
        $this->exactKeys($scoring, ['strategy','max_points','rules']);
        $allowed = ['none','single_choice','multiple_all_or_nothing','multiple_partial','all_answers_correct','yes_no','numeric_exact','numeric_tolerance','manual'];
        if (!in_array($scoring['strategy'], $allowed, true) || !is_numeric($scoring['max_points']) || !is_array($scoring['rules'])) $this->invalid('scoring_rule');
        if ($scoring['strategy'] === 'single_choice' && !in_array($component['type'], ['single_choice','dropdown'], true)) $this->invalid('scoring_rule');
        if (in_array($scoring['strategy'], ['multiple_all_or_nothing','multiple_partial'], true) && $component['type'] !== 'multiple_choice') $this->invalid('scoring_rule');
        if ($scoring['strategy'] === 'yes_no' && $component['type'] !== 'yes_no') $this->invalid('scoring_rule');
        if (in_array($scoring['strategy'], ['numeric_exact','numeric_tolerance'], true) && $component['type'] !== 'number') $this->invalid('scoring_rule');
        $correct = $scoring['rules']['correct'] ?? null;
        if ($scoring['strategy'] === 'single_choice' && $correct !== null && $correct !== '' && !in_array((string) $correct, $optionValues, true)) $this->invalid('scoring_rule');
        if (in_array($scoring['strategy'], ['multiple_all_or_nothing','multiple_partial'], true) && $correct !== null && array_diff(array_map('strval', (array) $correct), $optionValues)) $this->invalid('scoring_rule');
        if ($scoring['strategy'] === 'yes_no' && $correct !== null && $correct !== '' && !in_array((string) $correct, ['0','1'], true)) $this->invalid('scoring_rule');
        if (in_array($scoring['strategy'], ['numeric_exact','numeric_tolerance'], true) && $correct !== null && $correct !== '' && !is_numeric($correct)) $this->invalid('scoring_rule');
        if ($scoring['strategy'] === 'numeric_tolerance' && $correct !== null && $correct !== '' && (!is_numeric($scoring['rules']['tolerance'] ?? null) || (float) $scoring['rules']['tolerance'] < 0)) $this->invalid('scoring_rule');
    }

    private function summary(string $name, array $manifest, ?Organisation $organisation): array
    {
        $components = array_sum(array_map(fn ($section) => count($section['components']), $manifest['sections']));
        return [
            'package_name' => $name, 'valid' => true, 'name' => $manifest['form']['name'], 'content_hash' => $manifest['content_hash'],
            'schema_version' => $manifest['schema_version'], 'sections' => count($manifest['sections']), 'components' => $components,
            'has_assets' => count($manifest['attachments']) > 0,
            'duplicate' => $organisation ? QuestionnairePackageImport::where('organisation_id', $organisation->id)->where('content_hash', $manifest['content_hash'])->exists() : false,
        ];
    }

    private function packageDirectory(string $packageName): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*--[a-f0-9]{8}$/', $packageName) || basename($packageName) !== $packageName) $this->invalid('package');
        $directory = $this->root().DIRECTORY_SEPARATOR.$packageName;
        if (!File::isDirectory($directory)) $this->invalid('package');
        return $directory;
    }

    private function assetPath(string $directory, mixed $relative): string
    {
        if (!is_string($relative) || !preg_match('#^assets/[a-zA-Z0-9][a-zA-Z0-9._-]*$#', $relative) || str_contains($relative, '..') || preg_match('#^[A-Za-z]:|^[/\\\\]#', $relative)) $this->invalid('asset_path');
        $base = realpath($directory); $absolute = realpath($directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if (!$base || !$absolute || !str_starts_with($absolute, $base.DIRECTORY_SEPARATOR) || !File::isFile($absolute)) $this->invalid('asset_path');
        return $absolute;
    }

    private function translations(mixed $translations): void
    {
        if ($translations === null) return;
        if (!is_array($translations) || array_diff(array_keys($translations), config('form_locales.supported', []))) $this->invalid('translations');
        foreach ($translations as $values) if (!is_array($values)) $this->invalid('translations');
    }

    private function exactKeys(mixed $value, array $keys): void
    {
        if (!is_array($value) || array_values(array_unique(array_keys($value))) !== array_values(array_unique($keys))) {
            $actual = is_array($value) ? array_keys($value) : []; sort($actual); $expected = $keys; sort($expected);
            if ($actual !== $expected) $this->invalid('manifest');
        }
    }

    private function portableKey(mixed $value): bool { return is_string($value) && Str::isUuid($value); }
    private function availablePortableKey(string $preferred, array &$used): string
    {
        $key = isset($used[$preferred]) ? (string) Str::uuid() : $preferred;
        while (isset($used[$key])) $key = (string) Str::uuid();
        $used[$key] = true;
        return $key;
    }
    private function root(): string { return rtrim((string) config('questionnaire_packages.root', base_path('questionnaires')), '\\/'); }

    private function packageName(Form $form, string $hash): string
    {
        return Str::limit(Str::slug($form->name) ?: 'anketa', 80, '').'--'.substr($hash, 0, 8);
    }

    private function uniqueSlug(int $organisationId, string $name): string
    {
        $base = Str::slug($name) ?: 'form'; $slug = $base; $number = 2;
        while (Form::withTrashed()->where('organisation_id', $organisationId)->where('slug', $slug)->exists()) $slug = $base.'-'.$number++;
        return $slug;
    }

    private function canonicalJson(array $value): string { return json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function prettyJson(array $value): string { return json_encode($this->canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('messages.invalid_questionnaire_package')]);
    }
}
