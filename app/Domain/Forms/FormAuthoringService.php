<?php

namespace App\Domain\Forms;

use App\Domain\Audit\AuditService;
use App\Models\ConditionalRule;
use App\Models\Form;
use App\Models\FormComponent;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormAuthoringService
{
    public function __construct(private AuditService $audit, private ComponentRegistry $registry, private ScoringRuleValidator $scoringRules, private LocalizedContent $localized) {}

    public function create(int $organisationId, User $creator, string $name, string $preset): Form
    {
        return DB::transaction(function () use ($organisationId, $creator, $name, $preset) {
            $form = Form::create([
                'organisation_id' => $organisationId,
                'created_by' => $creator->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($organisationId, $name),
                'preset_key' => $preset,
                'status' => 'draft',
                'translations' => ['lv' => ['name' => $name]],
            ]);
            $version = $form->versions()->create([
                'version_number' => 1,
                'status' => 'draft',
                'title' => $name,
                'translations' => ['lv' => ['title' => $name]],
                'created_by' => $creator->id,
                'settings' => $this->presetSettings($preset),
            ]);
            $sectionTitle = __('messages.first_section', [], 'lv');
            $section = $version->sections()->create(['stable_key' => (string) Str::uuid(), 'title' => $sectionTitle, 'translations' => ['lv' => ['title' => $sectionTitle]], 'display_order' => 0]);
            $this->applyPreset($version, $section, $preset);
            $this->audit->record('form.created', $form, $organisationId, ['preset' => $preset]);
            return $form->load('versions.sections.components.options');
        });
    }

    public function addSection(FormVersion $version, string|array $input): FormSection
    {
        $this->ensureDraft($version);
        $translations = is_array($input)
            ? $this->localized->normalize($input['translations'] ?? null, ['title', 'description'])
            : ['lv' => ['title' => trim($input)]];
        $title = data_get($translations, 'lv.title');
        return DB::transaction(fn () => $version->sections()->create([
            'stable_key' => (string) Str::uuid(),
            'title' => $title,
            'description' => data_get($translations, 'lv.description'),
            'translations' => $translations,
            'display_order' => ((int) $version->sections()->max('display_order')) + 1,
        ]));
    }

    public function addComponent(FormVersion $version, FormSection $section, array $data): FormComponent
    {
        $this->ensureDraft($version);
        if ($section->form_version_id !== $version->id) throw ValidationException::withMessages(['section' => __('messages.invalid_section')]);
        if (count($data['options'] ?? []) > 100) throw ValidationException::withMessages(['options' => __('validation.max.array', ['max' => 100])]);
        $definition = $this->registry->definition($data['type']);
        $translations = $this->componentTranslations($data);
        $settings = $this->componentSettings($data['type'], $data['settings'] ?? [], $translations);
        if (!empty($settings['attachment_id']) && !$version->attachments()->whereKey($settings['attachment_id'])->exists()) {
            throw ValidationException::withMessages(['settings.attachment_id' => __('messages.invalid_attachment')]);
        }

        return DB::transaction(function () use ($version, $section, $data, $definition, $settings, $translations) {
            $label = data_get($translations, 'lv.label') ?: trim((string) ($data['label'] ?? '')) ?: $definition['name'];
            $component = $section->components()->create([
                'form_version_id' => $version->id,
                'stable_key' => (string) Str::uuid(),
                'type' => $data['type'],
                'label' => $label,
                'description' => data_get($translations, 'lv.description') ?: $this->nullableString($data['description'] ?? null),
                'help_text' => data_get($translations, 'lv.help_text') ?: $this->nullableString($data['help_text'] ?? null),
                'display_order' => ((int) $section->components()->max('display_order')) + 1,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'visible' => (bool) ($data['visible'] ?? true),
                'max_points' => (float) ($data['max_points'] ?? 0),
                'manual_grading' => (bool) ($data['manual_grading'] ?? false),
                'settings' => $settings,
                'translations' => $translations,
            ]);
            foreach ($data['options'] ?? [] as $index => $optionInput) {
                $optionTranslations = is_array($optionInput)
                    ? $this->localized->normalize($optionInput['translations'] ?? $optionInput, ['label'])
                    : ['lv' => ['label' => trim((string) $optionInput)]];
                $optionLabel = data_get($optionTranslations, 'lv.label');
                if (!$this->localized->isPresent($optionLabel)) continue;
                $component->options()->create(['stable_key' => (string) Str::uuid(), 'label' => $optionLabel, 'value' => (string) Str::uuid(), 'display_order' => $index, 'translations' => $optionTranslations]);
            }
            if (!empty($data['scoring_strategy']) && $data['scoring_strategy'] !== 'none') {
                $rules = $this->scoringRules->validate($component, $data['scoring_strategy'], $data['scoring_rules'] ?? []);
                $component->scoringRule()->create(['strategy' => $data['scoring_strategy'], 'max_points' => $component->max_points, 'rules' => $rules]);
            }
            return $component->load('options', 'scoringRule');
        });
    }

    public function publish(FormVersion $version): FormVersion
    {
        $this->ensureDraft($version);
        $version->load('sections.components.options', 'components.scoringRule', 'conditionalRules.actions');
        if ($version->sections->isEmpty()) throw ValidationException::withMessages(['form' => __('messages.form_needs_section')]);

        $componentIds = $version->components->pluck('id');
        foreach ($version->conditionalRules as $rule) {
            if (!$componentIds->contains($rule->source_component_id)) throw ValidationException::withMessages(['conditions' => __('messages.invalid_condition_reference')]);
            foreach ($rule->actions as $action) {
                if ($action->target_component_id === $rule->source_component_id) throw ValidationException::withMessages(['conditions' => __('messages.condition_self_reference')]);
                if ($action->target_component_id && !$componentIds->contains($action->target_component_id)) throw ValidationException::withMessages(['conditions' => __('messages.invalid_condition_reference')]);
            }
        }
        foreach ($version->components as $component) {
            if (in_array($component->type, ['single_choice', 'multiple_choice', 'dropdown'], true) && $component->options->count() < 2) {
                throw ValidationException::withMessages(['options' => __('messages.minimum_choice_options_required')]);
            }
            if ($component->scoringRule) $this->scoringRules->validate($component, $component->scoringRule->strategy, $component->scoringRule->rules ?? [], true);
        }

        return DB::transaction(function () use ($version) {
            $hash = hash('sha256', json_encode($version->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $version->update(['status' => 'published', 'published_at' => now(), 'content_hash' => $hash]);
            $version->form()->update(['status' => 'published']);
            $this->audit->record('form.published', $version, $version->form->organisation_id, ['version' => $version->version_number, 'hash' => $hash]);
            return $version->fresh();
        });
    }

    public function createDraftFrom(FormVersion $published, User $creator): FormVersion
    {
        if ($published->status !== 'published') throw ValidationException::withMessages(['version' => __('messages.version_not_published')]);
        $published->load('sections.components.options', 'sections.components.validationRules', 'sections.components.scoringRule', 'conditionalRules.actions');

        return DB::transaction(function () use ($published, $creator) {
            $draft = $published->form->versions()->create(['version_number' => ((int) $published->form->versions()->max('version_number')) + 1, 'status' => 'draft', 'title' => $published->title, 'description' => $published->description, 'translations' => $published->translations, 'settings' => $published->settings, 'created_by' => $creator->id]);
            $this->copyStructure($published, $draft);
            $this->audit->record('form.draft_created', $draft, $published->form->organisation_id, ['source_version' => $published->version_number]);
            return $draft;
        });
    }

    public function duplicate(Form $form, User $creator): Form
    {
        return DB::transaction(function () use ($form, $creator) {
            $source = $form->versions()->latest('version_number')->firstOrFail();
            $copy = $this->create($form->organisation_id, $creator, $form->name.' copy', 'blank');
            $draft = $copy->versions()->first();
            $draft->sections()->each(fn ($section) => $section->delete());
            $this->copyStructure($source, $draft);
            $draft->update(['title' => $source->title, 'description' => $source->description, 'translations' => $source->translations, 'settings' => $source->settings]);
            $this->audit->record('form.duplicated', $copy, $copy->organisation_id, ['source_form_id' => $form->id]);
            return $copy;
        });
    }

    public function archive(Form $form): void
    {
        DB::transaction(function () use ($form) {
            $form->update(['status' => 'archived']);
            $form->publications()->update(['status' => 'inactive']);
            $this->audit->record('form.archived', $form, $form->organisation_id);
        });
    }

    private function ensureDraft(FormVersion $version): void
    {
        if ($version->status !== 'draft') throw ValidationException::withMessages(['version' => __('messages.published_immutable')]);
    }

    private function copyStructure(FormVersion $source, FormVersion $target): void
    {
        $source->loadMissing('sections.components.options', 'sections.components.validationRules', 'sections.components.scoringRule', 'conditionalRules.actions', 'attachments');
        $sectionMap=[];$componentMap=[];$attachmentMap=[];
        foreach ($source->attachments as $attachment) {
            if (!Storage::disk($attachment->disk)->exists($attachment->storage_path)) throw ValidationException::withMessages(['attachments' => __('messages.invalid_attachment')]);
            $extension = pathinfo($attachment->storage_path, PATHINFO_EXTENSION);
            $path = 'attachments/'.$target->form->organisation_id.'/'.Str::uuid().($extension ? '.'.$extension : '');
            if (!Storage::disk($attachment->disk)->copy($attachment->storage_path, $path)) throw ValidationException::withMessages(['attachments' => __('messages.invalid_attachment')]);
            $copy = $target->attachments()->create(['organisation_id'=>$target->form->organisation_id,'uploaded_by'=>$target->created_by,'disk'=>$attachment->disk,'storage_path'=>$path,'original_name'=>$attachment->original_name,'mime_type'=>$attachment->mime_type,'size'=>$attachment->size,'sha256'=>$attachment->sha256,'status'=>'ready']);
            $attachmentMap[$attachment->id] = $copy->id;
        }
        foreach($source->sections as $section){$sectionCopy=$target->sections()->create(['stable_key'=>$section->stable_key,'title'=>$section->title,'description'=>$section->description,'display_order'=>$section->display_order,'visible'=>$section->visible,'translations'=>$section->translations]);$sectionMap[$section->id]=$sectionCopy->id;foreach($section->components as $component){$settings=$component->settings;if(isset($settings['attachment_id'])&&isset($attachmentMap[$settings['attachment_id']]))$settings['attachment_id']=$attachmentMap[$settings['attachment_id']];$componentCopy=$sectionCopy->components()->create(['form_version_id'=>$target->id,'stable_key'=>$component->stable_key,'type'=>$component->type,'label'=>$component->label,'description'=>$component->description,'help_text'=>$component->help_text,'display_order'=>$component->display_order,'is_required'=>$component->is_required,'visible'=>$component->visible,'max_points'=>$component->max_points,'manual_grading'=>$component->manual_grading,'settings'=>$settings,'translations'=>$component->translations]);$componentMap[$component->id]=$componentCopy->id;foreach($component->options as $option)$componentCopy->options()->create(['stable_key'=>$option->stable_key,'label'=>$option->label,'value'=>$option->value,'display_order'=>$option->display_order,'translations'=>$option->translations]);foreach($component->validationRules as $rule)$componentCopy->validationRules()->create(['rule_type'=>$rule->rule_type,'display_order'=>$rule->display_order,'parameters'=>$rule->parameters,'message_translations'=>$rule->message_translations]);if($component->scoringRule)$componentCopy->scoringRule()->create(['strategy'=>$component->scoringRule->strategy,'max_points'=>$component->scoringRule->max_points,'rules'=>$component->scoringRule->rules]);}}
        foreach($source->conditionalRules as $rule){$ruleCopy=$target->conditionalRules()->create(['source_component_id'=>$componentMap[$rule->source_component_id],'operator'=>$rule->operator,'comparison_value'=>$rule->comparison_value,'priority'=>$rule->priority]);foreach($rule->actions as $action)$ruleCopy->actions()->create(['action'=>$action->action,'target_component_id'=>$action->target_component_id?$componentMap[$action->target_component_id]:null,'target_section_id'=>$action->target_section_id?$sectionMap[$action->target_section_id]:null]);}
    }

    private function uniqueSlug(int $organisationId, string $name): string
    {
        $base = Str::slug($name) ?: 'form'; $slug = $base; $i = 2;
        while (Form::withTrashed()->where('organisation_id', $organisationId)->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
        return $slug;
    }

    private function presetSettings(string $preset): array
    {
        return match ($preset) {
            'test' => ['scoring_enabled' => true, 'timer_enabled' => true, 'duration_minutes' => 30, 'attempt_limit' => 1, 'identified_required' => true],
            'patient_questionnaire' => ['scoring_enabled' => false, 'consent_required' => true, 'anonymous_allowed' => true, 'result_visibility' => 'completion'],
            default => ['scoring_enabled' => false],
        };
    }

    private function applyPreset(FormVersion $version, FormSection $section, string $preset): void
    {
        if ($preset === 'test') {
            $component = $this->addComponent($version, $section, ['type' => 'single_choice', 'label' => __('messages.sample_question', [], 'lv'), 'is_required' => true, 'max_points' => 1, 'options' => [__('messages.option_a', [], 'lv'), __('messages.option_b', [], 'lv')], 'scoring_strategy' => 'single_choice', 'scoring_rules' => []]);
            $component->scoringRule()->update(['rules' => ['correct' => $component->options()->orderBy('display_order')->value('value')]]);
        }
        if ($preset === 'patient_questionnaire') {
            $consentText = __('messages.demo_consent_text', [], 'lv');
            $this->addComponent($version, $section, ['type' => 'consent_checkbox', 'label' => __('messages.consent_label', [], 'lv'), 'description' => $consentText, 'is_required' => true, 'settings' => ['consent_text' => $consentText, 'refusal_policy' => 'block'], 'options' => []]);
            $this->addComponent($version, $section, ['type' => 'long_text', 'label' => __('messages.sample_questionnaire_prompt', [], 'lv'), 'is_required' => false, 'options' => []]);
        }
    }

    private function componentTranslations(array $data): ?array
    {
        $translations = $this->localized->normalize($data['translations'] ?? null, [
            'label', 'description', 'help_text', 'placeholder', 'consent_text',
            'minimum_label', 'maximum_label', 'image_title', 'image_caption',
        ]) ?? [];

        foreach (['label', 'description', 'help_text'] as $field) {
            if (!data_get($translations, 'lv.'.$field) && $this->localized->isPresent($data[$field] ?? null)) {
                $translations['lv'][$field] = trim((string) $data[$field]);
            }
        }

        foreach (['placeholder', 'consent_text', 'minimum_label', 'maximum_label', 'image_title', 'image_caption'] as $field) {
            if (!data_get($translations, 'lv.'.$field) && $this->localized->isPresent(data_get($data, 'settings.'.$field))) {
                $translations['lv'][$field] = trim((string) data_get($data, 'settings.'.$field));
            }
        }

        return $translations ?: null;
    }

    private function componentSettings(string $type, array $settings, ?array $translations): array
    {
        foreach (['placeholder', 'consent_text', 'minimum_label', 'maximum_label', 'image_title', 'image_caption'] as $field) {
            $value = data_get($translations, 'lv.'.$field);
            if ($this->localized->isPresent($value)) $settings[$field] = $value;
            elseif (array_key_exists($field, $settings) && !$this->localized->isPresent($settings[$field])) unset($settings[$field]);
        }

        return $this->registry->filterSettings($type, $settings);
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->localized->isPresent($value) ? trim((string) $value) : null;
    }
}
