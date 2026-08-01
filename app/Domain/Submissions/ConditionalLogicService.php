<?php

namespace App\Domain\Submissions;

use App\Models\FormVersion;

class ConditionalLogicService
{
    public function visibility(FormVersion $version, array $answers): array
    {
        $version->loadMissing('sections.components', 'conditionalRules.actions');
        $result = [
            'components' => $version->components->mapWithKeys(fn ($component) => [$component->id => (bool) $component->visible])->all(),
            'sections' => $version->sections->mapWithKeys(fn ($section) => [$section->id => (bool) $section->visible])->all(),
        ];

        // A show action is opt-in: its target starts hidden and becomes visible
        // only when a matching rule enables it. Every evaluation starts here,
        // so a previously matched rule cannot leave stale browser/server state.
        foreach ($version->conditionalRules as $rule) {
            foreach ($rule->actions as $action) {
                if ($action->action === 'show_component' && $action->target_component_id) $result['components'][$action->target_component_id] = false;
                if ($action->action === 'show_section' && $action->target_section_id) $result['sections'][$action->target_section_id] = false;
            }
        }

        foreach ($version->conditionalRules->sortBy('priority') as $rule) {
            $actual = $answers[$rule->source_component_id] ?? null;
            if (!$this->matches($rule->operator, $actual, $rule->comparison_value)) continue;
            foreach ($rule->actions as $action) {
                $visible = str_starts_with($action->action, 'show_');
                if ($action->target_component_id) $result['components'][$action->target_component_id] = $visible;
                if ($action->target_section_id) $result['sections'][$action->target_section_id] = $visible;
            }
        }
        return $result;
    }

    public function componentIsVisible(FormVersion $version, int $componentId, array $visibility): bool
    {
        $component = $version->components->firstWhere('id', $componentId);
        if (!$component) return false;

        return ($visibility['components'][$component->id] ?? (bool) $component->visible)
            && ($visibility['sections'][$component->form_section_id] ?? (bool) $component->section?->visible);
    }

    public function matches(string $operator, mixed $actual, mixed $expected): bool
    {
        $expected = is_array($expected) && array_key_exists('value', $expected) ? $expected['value'] : $expected;
        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'contains' => is_array($actual) ? in_array($expected, $actual, true) : str_contains((string) $actual, (string) $expected),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'is_answered' => $actual !== null && $actual !== '' && $actual !== [],
            'is_not_answered' => $actual === null || $actual === '' || $actual === [],
            default => false,
        };
    }
}
