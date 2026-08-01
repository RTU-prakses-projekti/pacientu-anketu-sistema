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
    public function __construct(private AuditService $audit, private ComponentRegistry $registry, private ScoringRuleValidator $scoringRules) {}

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
                'created_by' => $creator->id,
                'settings' => $this->presetSettings($preset),
            ]);
            $section = $version->sections()->create(['stable_key' => (string) Str::uuid(), 'title' => __('messages.first_section'), 'display_order' => 0]);
            $this->applyPreset($version, $section, $preset);
            $this->audit->record('form.created', $form, $organisationId, ['preset' => $preset]);
            return $form->load('versions.sections.components.options');
        });
    }

    public function addSection(FormVersion $version, string $title): FormSection
    {
        $this->ensureDraft($version);
        return DB::transaction(fn () => $version->sections()->create([
            'stable_key' => (string) Str::uuid(),
            'title' => $title,
            'display_order' => ((int) $version->sections()->max('display_order')) + 1,
        ]));
    }

    public function addComponent(FormVersion $version, FormSection $section, array $data): FormComponent
    {
        $this->ensureDraft($version);
        if ($section->form_version_id !== $version->id) throw ValidationException::withMessages(['section' => __('messages.invalid_section')]);
        $definition = $this->registry->definition($data['type']);
        $settings = $this->registry->filterSettings($data['type'], $data['settings'] ?? []);
        if (!empty($settings['attachment_id']) && !$version->attachments()->whereKey($settings['attachment_id'])->exists()) {
            throw ValidationException::withMessages(['settings.attachment_id' => __('messages.invalid_attachment')]);
        }

        return DB::transaction(function () use ($version, $section, $data, $definition, $settings) {
            $component = $section->components()->create([
                'form_version_id' => $version->id,
                'stable_key' => (string) Str::uuid(),
                'type' => $data['type'],
                'label' => $data['label'] ?: $definition['name'],
                'description' => $data['description'] ?? null,
                'help_text' => $data['help_text'] ?? null,
                'display_order' => ((int) $section->components()->max('display_order')) + 1,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'visible' => (bool) ($data['visible'] ?? true),
                'max_points' => (float) ($data['max_points'] ?? 0),
                'manual_grading' => (bool) ($data['manual_grading'] ?? false),
                'settings' => $settings,
                'translations' => $data['translations'] ?? null,
            ]);
            foreach ($data['options'] ?? [] as $index => $label) {
                if (trim((string) $label) === '') continue;
                $component->options()->create(['stable_key' => (string) Str::uuid(), 'label' => trim($label), 'value' => Str::slug($label).'-'.($index + 1), 'display_order' => $index]);
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
            $draft = $published->form->versions()->create(['version_number' => ((int) $published->form->versions()->max('version_number')) + 1, 'status' => 'draft', 'settings' => $published->settings, 'created_by' => $creator->id]);
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
            $draft->update(['settings' => $source->settings]);
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
            $component = $this->addComponent($version, $section, ['type' => 'single_choice', 'label' => __('messages.sample_question'), 'is_required' => true, 'max_points' => 1, 'options' => [__('messages.option_a'), __('messages.option_b')], 'scoring_strategy' => 'single_choice', 'scoring_rules' => []]);
            $component->scoringRule()->update(['rules' => ['correct' => $component->options()->orderBy('display_order')->value('value')]]);
        }
        if ($preset === 'patient_questionnaire') {
            $this->addComponent($version, $section, ['type' => 'consent_checkbox', 'label' => __('messages.consent_label'), 'description' => __('messages.demo_consent_text'), 'is_required' => true, 'settings' => ['consent_text' => __('messages.demo_consent_text'), 'refusal_policy' => 'block'], 'options' => []]);
            $this->addComponent($version, $section, ['type' => 'long_text', 'label' => __('messages.sample_questionnaire_prompt'), 'is_required' => false, 'options' => []]);
        }
    }
}
