@extends('layouts.app')
@section('content')
<div class="page-header">
    <div><a href="{{ route('forms.show',$form) }}">{{ __('messages.back') }}</a><h1>{{ $form->name }} · {{ __('messages.builder') }}</h1></div>
    <a class="btn" href="{{ route('forms.preview',$form) }}">{{ __('messages.preview') }}</a>
</div>
<form method="POST" action="{{ route('forms.update',$form) }}" class="card form-grid mb-6">@csrf @method('PUT')
    <label>LV<input name="name" value="{{ $form->name }}" required></label>
    <label>EN<input name="translations[en][name]" value="{{ data_get($form->translations,'en.name') }}"></label>
    <label>RU<input name="translations[ru][name]" value="{{ data_get($form->translations,'ru.name') }}"></label>
    <button class="btn">{{ __('messages.save') }}</button>
</form>
@unless($version)
    <div class="notice">{{ __('messages.published_immutable') }} {{ __('messages.new_draft') }}</div>
@else
<div class="builder-layout">
<aside class="card">
    <h2>{{ __('messages.attachments') }}</h2>
    <form method="POST" enctype="multipart/form-data" action="{{ route('attachments.store',[$form,$version]) }}" class="stack">@csrf<input type="file" name="file" required><button class="btn">{{ __('messages.upload') }}</button></form>
    @foreach($version->attachments as $attachment)<div class="list-row"><a href="{{ route('attachments.download',$attachment) }}">{{ $attachment->original_name }}</a><form method="POST" action="{{ route('attachments.destroy',$attachment) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div>@endforeach
    <hr><h2>{{ __('messages.add_component') }}</h2>
    <form method="POST" action="{{ route('builder.components.store',$form) }}" class="stack" data-component-form data-registry='@json($registry)'>@csrf
        <label>{{ __('messages.section') }}<select name="section_id">@foreach($version->sections as $section)<option value="{{ $section->id }}">{{ $section->title }}</option>@endforeach</select></label>
        <label>{{ __('messages.component_type') }}<select name="type" data-component-type>@foreach($registry as $key=>$definition)<option value="{{ $key }}">{{ $definition['name'] }} · {{ $definition['category'] }}</option>@endforeach</select></label>
        <label>{{ __('messages.label') }}<input name="label"></label><label>{{ __('messages.description') }}<textarea name="description"></textarea></label><label>{{ __('messages.help_text') }}<input name="help_text"></label>
        <div data-setting="placeholder"><label>{{ __('messages.placeholder') }}<input name="settings[placeholder]"></label></div>
        <div data-setting="minimum"><label>{{ __('messages.minimum') }}<input type="number" step="any" name="settings[minimum]"></label></div><div data-setting="maximum"><label>{{ __('messages.maximum') }}<input type="number" step="any" name="settings[maximum]"></label></div>
        <div data-setting="min_length"><label>{{ __('messages.min_length') }}<input type="number" name="settings[min_length]"></label></div><div data-setting="max_length"><label>{{ __('messages.max_length') }}<input type="number" name="settings[max_length]"></label></div>
        <div data-setting="consent_text"><label>{{ __('messages.description') }}<textarea name="settings[consent_text]"></textarea></label></div>
        <div data-setting="attachment_id"><label>{{ __('messages.attachment') }}<select name="settings[attachment_id]"><option value="">—</option>@foreach($version->attachments as $attachment)<option value="{{ $attachment->id }}">{{ $attachment->original_name }}</option>@endforeach</select></label></div>
        <fieldset data-options><legend>{{ __('messages.options') }}</legend>@for($i=0;$i<5;$i++)<input name="options[]" placeholder="{{ __('messages.options') }} {{ $i+1 }}">@endfor</fieldset>
        <label>{{ __('messages.max_points') }}<input type="number" step="0.01" min="0" name="max_points" value="0"></label>
        <label>{{ __('messages.scoring_strategy') }}<select name="scoring_strategy"><option value="none">—</option>@foreach(['single_choice','multiple_all_or_nothing','multiple_partial','yes_no','numeric_exact','numeric_tolerance','manual'] as $strategy)<option value="{{ $strategy }}">{{ $strategy }}</option>@endforeach</select></label>
        <p class="help">{{ __('messages.correct_answer_after_options') }}</p>
        <label class="check"><input type="checkbox" name="is_required" value="1"> {{ __('messages.required') }}</label><label class="check"><input type="checkbox" name="visible" value="1" checked> {{ __('messages.visible') }}</label><label class="check"><input type="checkbox" name="manual_grading" value="1"> {{ __('messages.manual_grading') }}</label>
        <button class="btn primary">{{ __('messages.add_component') }}</button>
    </form>
    <hr><h2>{{ __('messages.add_section') }}</h2><form method="POST" action="{{ route('builder.sections.store',$form) }}" class="stack">@csrf<label>{{ __('messages.section_title') }}<input name="title" required></label><button class="btn">{{ __('messages.add_section') }}</button></form>
    <hr><h2>{{ __('messages.conditional_visibility') }}</h2><form method="POST" action="{{ route('builder.conditions.store',$form) }}" class="stack">@csrf
        <label>{{ __('messages.source') }}<select name="source_component_id">@foreach($version->components as $item)<option value="{{ $item->id }}">{{ $item->label }}</option>@endforeach</select></label>
        <label>{{ __('messages.operator') }}<select name="operator">@foreach(['equals','not_equals','contains','greater_than','less_than','is_answered','is_not_answered'] as $operator)<option>{{ $operator }}</option>@endforeach</select></label>
        <label>{{ __('messages.value') }}<input name="comparison_value"></label><label>{{ __('messages.action') }}<select name="action">@foreach(['show_component','hide_component','show_section','hide_section'] as $action)<option>{{ $action }}</option>@endforeach</select></label>
        <label>{{ __('messages.target_component') }}<select name="target_component_id"><option value="">—</option>@foreach($version->components as $item)<option value="{{ $item->id }}">{{ $item->label }}</option>@endforeach</select></label>
        <label>{{ __('messages.target_section') }}<select name="target_section_id"><option value="">—</option>@foreach($version->sections as $item)<option value="{{ $item->id }}">{{ $item->title }}</option>@endforeach</select></label><button class="btn">{{ __('messages.save') }}</button>
    </form>
    @foreach($version->conditionalRules as $condition)<div class="list-row"><small>{{ $condition->sourceComponent?->label }} {{ $condition->operator }} {{ data_get($condition->comparison_value,'value') }}</small><form method="POST" action="{{ route('builder.conditions.destroy',[$form,$condition]) }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div>@endforeach
</aside>
<div class="stack">
@foreach($version->sections as $section)
<section class="builder-section">
    <div class="page-header"><form method="POST" action="{{ route('builder.sections.update',[$form,$section]) }}" class="inline-fields">@csrf @method('PUT')<input name="title" value="{{ $section->title }}" aria-label="{{ __('messages.section_title') }}"><input name="description" value="{{ $section->description }}" placeholder="{{ __('messages.description') }}"><input type="hidden" name="visible" value="0"><label class="check"><input type="checkbox" name="visible" value="1" @checked($section->visible)> {{ __('messages.visible') }}</label><button class="btn">{{ __('messages.save') }}</button></form>
    <div class="actions">@foreach(['up'=>'move_up','down'=>'move_down'] as $direction=>$key)<form method="POST" action="{{ route('builder.sections.move',[$form,$section]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="icon-btn" title="{{ __('messages.'.$key) }}">{{ $direction==='up'?'↑':'↓' }}</button></form>@endforeach @if($section->components->isEmpty())<form method="POST" action="{{ route('builder.sections.destroy',[$form,$section]) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form>@endif</div></div>
    @foreach($section->components as $component)
    @php($correct=(array)data_get($component->scoringRule?->rules,'correct',[]))
    <article class="component-card"><div class="page-header"><div><span class="badge">{{ $component->type }}</span> <strong>{{ $component->label }}</strong></div><div class="actions"><form method="POST" action="{{ route('builder.components.copy',[$form,$component]) }}">@csrf<button class="icon-btn" title="{{ __('messages.copy') }}">⧉</button></form>@foreach(['up','down'] as $direction)<form method="POST" action="{{ route('builder.components.move',[$form,$component]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="icon-btn">{{ $direction==='up'?'↑':'↓' }}</button></form>@endforeach<form method="POST" action="{{ route('builder.components.destroy',[$form,$component]) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div></div>
    <form method="POST" action="{{ route('builder.components.update',[$form,$component]) }}" class="form-grid">@csrf @method('PUT')
        <label>{{ __('messages.label') }}<input name="label" value="{{ $component->label }}" required></label><label>EN<input name="translations[en][label]" value="{{ data_get($component->translations,'en.label') }}"></label><label>RU<input name="translations[ru][label]" value="{{ data_get($component->translations,'ru.label') }}"></label>
        <label>{{ __('messages.description') }}<textarea name="description">{{ $component->description }}</textarea></label><label>{{ __('messages.help_text') }}<input name="help_text" value="{{ $component->help_text }}"></label>
        @if(in_array($component->type,['image','file_attachment']))<label>{{ __('messages.attachment') }}<select name="settings[attachment_id]"><option value="">—</option>@foreach($version->attachments as $attachment)<option value="{{ $attachment->id }}" @selected((int)data_get($component->settings,'attachment_id')===$attachment->id)>{{ $attachment->original_name }}</option>@endforeach</select></label>@endif
        <label>{{ __('messages.max_points') }}<input name="max_points" type="number" step="0.01" value="{{ $component->max_points }}"></label>
        @if(in_array($component->type,['single_choice','multiple_choice','dropdown']))<fieldset><legend>{{ __('messages.options') }}</legend>@foreach($component->options as $option)<input name="options[existing][{{ $option->id }}]" value="{{ $option->label }}">@endforeach<input name="options[new][]" placeholder="{{ __('messages.add_component') }}"></fieldset>@endif
        <label>{{ __('messages.scoring_strategy') }}<select name="scoring_strategy">@foreach(['none','single_choice','multiple_all_or_nothing','multiple_partial','yes_no','numeric_exact','numeric_tolerance','manual'] as $strategy)<option value="{{ $strategy }}" @selected(($component->scoringRule?->strategy??'none')===$strategy)>{{ $strategy }}</option>@endforeach</select></label>
        @if(in_array($component->type,['single_choice','dropdown']))<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach($component->options as $option)<label class="choice"><input type="radio" name="scoring_rules[correct]" value="{{ $option->value }}" @checked(in_array($option->value,$correct,true))> {{ $option->label }}</label>@endforeach</fieldset>
        @elseif($component->type==='multiple_choice')<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach($component->options as $option)<label class="choice"><input type="checkbox" name="scoring_rules[correct][]" value="{{ $option->value }}" @checked(in_array($option->value,$correct,true))> {{ $option->label }}</label>@endforeach</fieldset>
        @elseif($component->type==='yes_no')<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach(['1'=>__('messages.yes'),'0'=>__('messages.no')] as $value=>$label)<label class="choice"><input type="radio" name="scoring_rules[correct]" value="{{ $value }}" @checked((string)data_get($component->scoringRule?->rules,'correct')===$value)> {{ $label }}</label>@endforeach</fieldset>
        @elseif($component->type==='number')<label>{{ __('messages.correct_answers') }}<input type="number" step="any" name="scoring_rules[correct]" value="{{ data_get($component->scoringRule?->rules,'correct') }}"></label><label>{{ __('messages.tolerance') }}<input type="number" min="0" step="any" name="scoring_rules[tolerance]" value="{{ data_get($component->scoringRule?->rules,'tolerance') }}"></label>@endif
        <input type="hidden" name="visible" value="0"><label class="check"><input type="checkbox" name="visible" value="1" @checked($component->visible)> {{ __('messages.visible') }}</label><label class="check"><input type="checkbox" name="is_required" value="1" @checked($component->is_required)> {{ __('messages.required') }}</label><label class="check"><input type="checkbox" name="manual_grading" value="1" @checked($component->manual_grading)> {{ __('messages.manual_grading') }}</label>
        <label>{{ __('messages.move_to_section') }}<select name="move_section" data-move-url="{{ route('builder.components.move',[$form,$component]) }}"><option value="">—</option>@foreach($version->sections->where('id','!=',$section->id) as $target)<option value="{{ $target->id }}">{{ $target->title }}</option>@endforeach</select></label><button class="btn">{{ __('messages.save') }}</button>
    </form></article>
    @endforeach
</section>
@endforeach
</div></div>
@endunless
@endsection
