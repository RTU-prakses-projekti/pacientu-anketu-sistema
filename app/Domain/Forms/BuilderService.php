<?php

namespace App\Domain\Forms;

use App\Models\FormComponent;
use App\Models\FormSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuilderService
{
    public function __construct(private ComponentRegistry $registry, private ScoringRuleValidator $scoringRules, private LocalizedContent $localized) {}

    public function updateVersion(\App\Models\FormVersion $version, array $data): void
    {
        $translations = $this->localized->normalize($data['translations'] ?? null, ['title', 'description', 'completion_text', 'result_text']);
        $settings = $version->settings ?? [];
        foreach (['completion_text', 'result_text'] as $field) {
            $value = data_get($translations, 'lv.'.$field);
            if ($this->localized->isPresent($value)) $settings[$field] = $value;
            else unset($settings[$field]);
        }
        $version->update([
            'title' => data_get($translations, 'lv.title'),
            'description' => data_get($translations, 'lv.description'),
            'translations' => $translations,
            'settings' => $settings,
        ]);
    }

    public function updateSection(FormSection $section, array $data): void
    {
        $translations = $this->localized->normalize($data['translations'] ?? null, ['title', 'description']);
        $section->update(['title' => data_get($translations, 'lv.title'), 'description' => data_get($translations, 'lv.description'), 'visible' => (bool) ($data['visible'] ?? true), 'translations' => $translations]);
    }

    public function moveSection(FormSection $section, string $direction): void
    {
        DB::transaction(function () use ($section, $direction) {
            $operator = $direction === 'up' ? '<' : '>';
            $sort = $direction === 'up' ? 'desc' : 'asc';
            $other = FormSection::where('form_version_id', $section->form_version_id)->where('display_order', $operator, $section->display_order)->orderBy('display_order', $sort)->first();
            if (!$other) return;
            [$sectionOrder, $otherOrder] = [$section->display_order, $other->display_order];
            $section->update(['display_order' => $otherOrder]); $other->update(['display_order' => $sectionOrder]);
        });
    }

    public function deleteSection(FormSection $section): void
    {
        if ($section->components()->exists()) throw ValidationException::withMessages(['section' => __('messages.section_not_empty')]);
        $section->delete();
    }

    public function updateComponent(FormComponent $component, array $data): void
    {
        $translations = array_key_exists('translations', $data)
            ? $this->localized->normalize($data['translations'], ['label', 'description', 'help_text', 'placeholder', 'consent_text', 'minimum_label', 'maximum_label', 'image_title', 'image_caption'])
            : $component->translations;
        $settings = array_key_exists('settings', $data) ? ($data['settings'] ?? []) : ($component->settings ?? []);
        foreach (['placeholder', 'consent_text', 'minimum_label', 'maximum_label', 'image_title', 'image_caption'] as $field) {
            $value = data_get($translations, 'lv.'.$field);
            if ($this->localized->isPresent($value)) $settings[$field] = $value;
            elseif (array_key_exists($field, $settings) && !$this->localized->isPresent($settings[$field])) unset($settings[$field]);
        }
        $settings = $this->registry->filterSettings($component->type, $settings);
        if (!empty($settings['attachment_id']) && !$component->formVersion->attachments()->whereKey($settings['attachment_id'])->exists()) {
            throw ValidationException::withMessages(['settings.attachment_id' => __('messages.invalid_attachment')]);
        }
        DB::transaction(function () use ($component, $data, $settings, $translations) {
        $component->update([
            'label' => data_get($translations, 'lv.label'), 'description' => data_get($translations, 'lv.description'), 'help_text' => data_get($translations, 'lv.help_text'),
            'is_required' => (bool) ($data['is_required'] ?? false), 'visible' => (bool) ($data['visible'] ?? false),
            'max_points' => (float) ($data['max_points'] ?? 0), 'manual_grading' => (bool) ($data['manual_grading'] ?? false),
            'settings' => $settings, 'translations' => $translations,
        ]);
        if (array_key_exists('options', $data) && in_array($component->type, ['single_choice','multiple_choice','dropdown'], true)) {
            $this->syncOptions($component, $data['options']);
        }
        $strategy=$data['scoring_strategy']??'none';
        if($strategy==='none')$component->scoringRule?->delete();else{$rules=$this->scoringRules->validate($component,$strategy,$data['scoring_rules']??[]);$component->scoringRule()->updateOrCreate([],['strategy'=>$strategy,'max_points'=>$component->max_points,'rules'=>$rules]);}
        });
    }

    public function copyComponent(FormComponent $component): FormComponent
    {
        return DB::transaction(function () use ($component) {
            $copyLabel = $component->localizedLabel('lv').' (copy)';
            $translations = $component->translations ?? [];
            $translations['lv']['label'] = $copyLabel;
            $copy = $component->replicate(); $copy->stable_key = (string) Str::uuid(); $copy->label = $copyLabel; $copy->translations = $translations; $copy->display_order = ((int) $component->section->components()->max('display_order')) + 1; $copy->save();
            foreach ($component->options as $option) { $o = $option->replicate(); $o->stable_key = (string) Str::uuid(); $copy->options()->save($o); }
            foreach ($component->validationRules as $rule) $copy->validationRules()->save($rule->replicate());
            if ($component->scoringRule) $copy->scoringRule()->save($component->scoringRule->replicate());
            return $copy;
        });
    }

    public function moveComponent(FormComponent $component, ?FormSection $section, string $direction): void
    {
        DB::transaction(function () use ($component, $section, $direction) {
            if ($section && $section->form_version_id === $component->form_version_id && $section->id !== $component->form_section_id) {
                $component->update(['form_section_id' => $section->id, 'display_order' => ((int) $section->components()->max('display_order')) + 1]);
                return;
            }
            $operator = $direction === 'up' ? '<' : '>'; $sort = $direction === 'up' ? 'desc' : 'asc';
            $other = FormComponent::where('form_section_id', $component->form_section_id)->where('display_order', $operator, $component->display_order)->orderBy('display_order', $sort)->first();
            if (!$other) return;
            [$a, $b] = [$component->display_order, $other->display_order]; $component->update(['display_order' => $b]); $other->update(['display_order' => $a]);
        });
    }

    private function syncOptions(FormComponent $component, array $input): void
    {
        $component->load('options');
        if (array_key_exists('existing', $input) || array_key_exists('new', $input)) {
            $existing = collect($input['existing'] ?? [])->mapWithKeys(function ($optionInput, $id) use ($component) {
                $option = $component->options->firstWhere('id', (int) $id);
                if (!$option) return [];
                $translations = is_array($optionInput)
                    ? $this->localized->normalize($optionInput['translations'] ?? $optionInput, ['label'])
                    : ['lv' => ['label' => trim((string) $optionInput)]];
                $label = data_get($translations, 'lv.label');
                return $this->localized->isPresent($label) ? [(int) $id => ['label' => $label, 'translations' => $translations]] : [];
            });
            $component->options()->whereNotIn('id', $existing->keys())->delete();
            foreach ($existing as $id => $optionData) $component->options()->whereKey($id)->update($optionData);
            $new = collect($input['new'] ?? [])->map(function ($optionInput) {
                $translations = is_array($optionInput)
                    ? $this->localized->normalize($optionInput['translations'] ?? $optionInput, ['label'])
                    : ['lv' => ['label' => trim((string) $optionInput)]];
                $label = data_get($translations, 'lv.label');
                return $this->localized->isPresent($label) ? ['label' => $label, 'translations' => $translations] : null;
            })->filter()->values()->all();
        } else {
            $labels = array_values(array_filter(array_map(fn ($label) => trim((string) $label), $input)));
            $current = $component->options->values();
            foreach ($labels as $index => $label) {
                if ($current->has($index)) $current[$index]->update(['label' => $label, 'display_order' => $index]);
                else $component->options()->create(['stable_key'=>(string)Str::uuid(),'label'=>$label,'value'=>(string)Str::uuid(),'display_order'=>$index]);
            }
            $component->options()->whereNotIn('id', $current->take(count($labels))->pluck('id'))->delete();
            $new = [];
        }
        $order = (int) $component->options()->max('display_order') + 1;
        foreach ($new as $optionData) $component->options()->create(['stable_key'=>(string)Str::uuid(),'label'=>$optionData['label'],'value'=>(string)Str::uuid(),'translations'=>$optionData['translations'],'display_order'=>$order++]);
    }
}
