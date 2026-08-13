<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use App\Models\Concerns\HasLocalizedContent;
use Illuminate\Database\Eloquent\Model;

class FormComponent extends Model
{
    use HasLocalizedContent, ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['is_required' => 'boolean', 'visible' => 'boolean', 'manual_grading' => 'boolean', 'settings' => 'array', 'translations' => 'array', 'max_points' => 'decimal:2'];
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function section() { return $this->belongsTo(FormSection::class, 'form_section_id'); }
    public function options() { return $this->hasMany(ComponentOption::class)->orderBy('display_order'); }
    public function validationRules() { return $this->hasMany(ValidationRule::class)->orderBy('display_order'); }
    public function scoringRule() { return $this->hasOne(ScoringRule::class); }
    public function immutableVersion() { return $this->formVersion; }
    public function localizedLabel(?string $locale = null): ?string { return $this->localized('label', $this->label, $locale); }
    public function localizedDescription(?string $locale = null): ?string { return $this->localized('description', $this->description, $locale); }
    public function localizedHelpText(?string $locale = null): ?string { return $this->localized('help_text', $this->help_text, $locale); }
    public function localizedPlaceholder(?string $locale = null): ?string { return $this->localized('placeholder', data_get($this->settings, 'placeholder'), $locale); }
    public function localizedConsentText(?string $locale = null): ?string { return $this->localized('consent_text', $this->consentTextBaseValue(), $locale); }
    public function localizedConsentTextSourceLocale(?string $locale = null): ?string
    {
        $source = $this->localizedSourceLocale('consent_text', $this->consentTextBaseValue(), $locale);

        return $source === 'base' ? (string) config('form_locales.default', 'lv') : $source;
    }
    public function localizedMinimumLabel(?string $locale = null): ?string { return $this->localized('minimum_label', data_get($this->settings, 'minimum_label'), $locale); }
    public function localizedMaximumLabel(?string $locale = null): ?string { return $this->localized('maximum_label', data_get($this->settings, 'maximum_label'), $locale); }
    public function localizedImageTitle(?string $locale = null): ?string { return $this->localized('image_title', data_get($this->settings, 'image_title') ?: $this->label, $locale); }
    public function localizedImageCaption(?string $locale = null): ?string { return $this->localized('image_caption', data_get($this->settings, 'image_caption'), $locale); }

    public function usesContentFallback(?string $locale = null): bool
    {
        if ($this->type === 'image') {
            $fields = [
                'image_title' => data_get($this->settings, 'image_title') ?: $this->label,
                'image_caption' => data_get($this->settings, 'image_caption'),
            ];
        } else {
            $fields = ['label' => $this->label];
            if ($this->type === 'explanatory_text') $fields['description'] = $this->description;
            if (!in_array($this->type, ['form_title', 'heading', 'explanatory_text', 'file_attachment'], true)) {
                $fields += ['description' => $this->description, 'help_text' => $this->help_text];
            }
            if (in_array($this->type, ['short_text', 'long_text', 'number', 'dropdown'], true)) $fields['placeholder'] = data_get($this->settings, 'placeholder');
            if ($this->type === 'consent_checkbox') $fields['consent_text'] = $this->consentTextBaseValue();
            if (in_array($this->type, ['rating_scale', 'linear_scale'], true)) $fields += ['minimum_label' => data_get($this->settings, 'minimum_label'), 'maximum_label' => data_get($this->settings, 'maximum_label')];
        }

        return $this->usesAnyLocalizedFallback($fields, $locale)
            || $this->options->contains(fn (ComponentOption $option) => $option->usesLocalizedFallback('label', $option->label, $locale));
    }

    public function localizedAnswerValue(mixed $value, ?string $locale = null): string
    {
        if (!in_array($this->type, ['single_choice', 'multiple_choice', 'dropdown'], true)) {
            return is_array($value) ? implode('; ', $value) : (string) ($value ?? '');
        }

        $values = array_map('strval', is_array($value) ? $value : [$value]);

        return $this->options
            ->whereIn('value', $values)
            ->map(fn (ComponentOption $option) => $option->localizedLabel($locale))
            ->filter(fn ($label) => $label !== null)
            ->implode('; ');
    }

    private function consentTextBaseValue(): ?string
    {
        $value = data_get($this->settings, 'consent_text');
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
