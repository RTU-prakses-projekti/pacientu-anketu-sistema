@extends('layouts.app')
@section('content')
@php
    $versionFields = [
        ['name'=>'title','label'=>__('messages.form_title'),'base'=>$version?->title,'required'=>true],
        ['name'=>'description','label'=>__('messages.description'),'base'=>$version?->description,'type'=>'textarea'],
        ['name'=>'completion_text','label'=>__('messages.completion_text'),'base'=>data_get($version?->settings,'completion_text'),'type'=>'textarea'],
        ['name'=>'result_text','label'=>__('messages.result_text'),'base'=>data_get($version?->settings,'result_text'),'type'=>'textarea'],
    ];
    $newComponentFields = [
        ['name'=>'label','label'=>__('messages.label'),'base'=>null,'required'=>true],
        ['name'=>'description','label'=>__('messages.description'),'base'=>null,'type'=>'textarea'],
        ['name'=>'help_text','label'=>__('messages.help_text'),'base'=>null],
        ['name'=>'placeholder','label'=>__('messages.placeholder'),'base'=>null,'setting'=>'placeholder'],
        ['name'=>'consent_text','label'=>__('messages.consent_text'),'base'=>null,'type'=>'textarea','setting'=>'consent_text'],
        ['name'=>'minimum_label','label'=>__('messages.minimum_label'),'base'=>null,'setting'=>'minimum_label'],
        ['name'=>'maximum_label','label'=>__('messages.maximum_label'),'base'=>null,'setting'=>'maximum_label'],
        ['name'=>'image_title','label'=>__('messages.image_title'),'base'=>null,'setting'=>'image_title'],
        ['name'=>'image_caption','label'=>__('messages.image_caption'),'base'=>null,'type'=>'textarea','setting'=>'image_caption'],
    ];
@endphp
<div class="page-header">
    <div><a href="{{ route('forms.show',$form) }}">{{ __('messages.back') }}</a><h1>{{ $form->name }} · {{ __('messages.builder') }}</h1></div>
    <div class="actions">@if($version)<a class="btn" href="{{ route('questionnaires.parts',[$form,$version]) }}">{{ __('messages.add_questionnaire_part_from_git') }}</a>@endif<a class="btn" href="{{ route('forms.preview',$form) }}?locale={{ config('form_locales.default') }}">{{ __('messages.preview') }}</a></div>
</div>

<form method="POST" action="{{ route('forms.update',$form) }}" class="card form-grid mb-6">@csrf @method('PUT')
    <label>{{ __('messages.administrative_form_name') }}<input name="name" value="{{ $form->name }}" required></label>
    <p class="help">{{ __('messages.administrative_form_name_help') }}</p>
    <button class="btn">{{ __('messages.save') }}</button>
</form>

@unless($version)
    <div class="notice">{{ __('messages.published_immutable') }} {{ __('messages.new_draft') }}</div>
@else
<form method="POST" action="{{ route('builder.versions.update',[$form,$version]) }}" class="card stack mb-6">@csrf @method('PUT')
    <h2>{{ __('messages.versioned_form_content') }}</h2>
    @include('forms.partials.locale-tabs',['group'=>'version-'.$version->id,'prefix'=>'translations','translations'=>$version->translations,'fields'=>$versionFields])
    <button class="btn">{{ __('messages.save') }}</button>
</form>

<div class="builder-layout">
<aside class="card">
    <h2>{{ __('messages.attachments') }}</h2>
    <form method="POST" enctype="multipart/form-data" action="{{ route('attachments.store',[$form,$version]) }}" class="stack">@csrf<input type="file" name="file" required><button class="btn">{{ __('messages.upload') }}</button></form>
    @foreach($version->attachments as $attachment)<div class="list-row"><a href="{{ route('attachments.download',$attachment) }}">{{ $attachment->original_name }}</a><form method="POST" action="{{ route('attachments.destroy',$attachment) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div>@endforeach

    <hr><h2>{{ __('messages.add_component') }}</h2>
    <form method="POST" action="{{ route('builder.components.store',$form) }}" class="stack" data-component-form data-registry='@json($registry)'>@csrf
        <label>{{ __('messages.section') }}<select name="section_id">@foreach($version->sections as $section)<option value="{{ $section->id }}">{{ $section->localizedTitle('lv') }}</option>@endforeach</select></label>
        <label>{{ __('messages.component_type') }}<select name="type" data-component-type>@foreach($registry as $key=>$definition)<option value="{{ $key }}">{{ in_array($key,['multiple_choice','consent_checkbox'],true) ? __('messages.component_type_'.$key) : $definition['name'] }} · {{ $definition['category'] }}</option>@endforeach</select></label>
        @include('forms.partials.locale-tabs',['group'=>'new-component','prefix'=>'translations','translations'=>null,'fields'=>$newComponentFields])
        <div data-setting="minimum"><label>{{ __('messages.minimum') }}<input type="number" step="any" name="settings[minimum]"></label></div>
        <div data-setting="maximum"><label>{{ __('messages.maximum') }}<input type="number" step="any" name="settings[maximum]"></label></div>
        <div data-setting="min_length"><label>{{ __('messages.min_length') }}<input type="number" name="settings[min_length]"></label></div>
        <div data-setting="max_length"><label>{{ __('messages.max_length') }}<input type="number" name="settings[max_length]"></label></div>
        <div data-setting="attachment_id"><label>{{ __('messages.attachment') }}<select name="settings[attachment_id]"><option value="">—</option>@foreach($version->attachments as $attachment)<option value="{{ $attachment->id }}">{{ $attachment->original_name }}</option>@endforeach</select></label></div>
        <fieldset data-options data-option-manager data-option-manager-key="new-component" data-next-option-index="2" data-max-options="100"><legend>{{ __('messages.options') }}</legend>
            <div data-option-list>
            @for($i=0;$i<2;$i++)
                <div class="option-editor" data-option-row>
                    <div class="page-header"><strong>{{ __('messages.option_number',['number'=>$i+1]) }}</strong><button type="button" class="btn danger" data-option-remove>{{ __('messages.remove_option') }}</button></div>
                    @include('forms.partials.locale-tabs',['group'=>'new-component-option-'.$i,'prefix'=>'options['.$i.'][translations]','translations'=>null,'fields'=>[['name'=>'label','label'=>__('messages.label'),'base'=>null]]])
                </div>
            @endfor
            </div>
            <button type="button" class="btn" data-option-add>{{ __('messages.add_option') }}</button>
            <template data-option-template>
                <div class="option-editor" data-option-row>
                    <div class="page-header"><strong>{{ __('messages.new_option') }}</strong><button type="button" class="btn danger" data-option-remove>{{ __('messages.remove_option') }}</button></div>
                    @include('forms.partials.locale-tabs',['group'=>'new-component-option-template','prefix'=>'options[__INDEX__][translations]','translations'=>null,'fields'=>[['name'=>'label','label'=>__('messages.label'),'base'=>null]]])
                </div>
            </template>
        </fieldset>
        <label>{{ __('messages.max_points') }}<input type="number" step="0.01" min="0" name="max_points" value="0"></label>
        <label>{{ __('messages.scoring_strategy') }}<select name="scoring_strategy"><option value="none">—</option>@foreach(['single_choice','multiple_all_or_nothing','multiple_partial','yes_no','numeric_exact','numeric_tolerance','manual'] as $strategy)<option value="{{ $strategy }}">{{ $strategy }}</option>@endforeach</select></label>
        <p class="help">{{ __('messages.correct_answer_after_options') }}</p>
        <label class="check"><input type="checkbox" name="is_required" value="1"> {{ __('messages.required') }}</label><label class="check"><input type="checkbox" name="visible" value="1" checked> {{ __('messages.visible') }}</label><label class="check"><input type="checkbox" name="manual_grading" value="1"> {{ __('messages.manual_grading') }}</label>
        <button class="btn primary">{{ __('messages.add_component') }}</button>
    </form>

    <hr><h2>{{ __('messages.add_section') }}</h2>
    <form method="POST" action="{{ route('builder.sections.store',$form) }}" class="stack">@csrf
        @include('forms.partials.locale-tabs',['group'=>'new-section','prefix'=>'translations','translations'=>null,'fields'=>[['name'=>'title','label'=>__('messages.section_title'),'base'=>null,'required'=>true],['name'=>'description','label'=>__('messages.description'),'base'=>null,'type'=>'textarea']]])
        <button class="btn">{{ __('messages.add_section') }}</button>
    </form>

    <hr><h2>{{ __('messages.conditional_visibility') }}</h2><form method="POST" action="{{ route('builder.conditions.store',$form) }}" class="stack">@csrf
        <label>{{ __('messages.source') }}<select name="source_component_id">@foreach($version->components as $item)<option value="{{ $item->id }}">{{ $item->localizedLabel('lv') }}</option>@endforeach</select></label>
        <label>{{ __('messages.operator') }}<select name="operator">@foreach(['equals','not_equals','contains','greater_than','less_than','is_answered','is_not_answered'] as $operator)<option>{{ $operator }}</option>@endforeach</select></label>
        <label>{{ __('messages.value') }}<input name="comparison_value"></label><label>{{ __('messages.action') }}<select name="action">@foreach(['show_component','hide_component','show_section','hide_section'] as $action)<option>{{ $action }}</option>@endforeach</select></label>
        <label>{{ __('messages.target_component') }}<select name="target_component_id"><option value="">—</option>@foreach($version->components as $item)<option value="{{ $item->id }}">{{ $item->localizedLabel('lv') }}</option>@endforeach</select></label>
        <label>{{ __('messages.target_section') }}<select name="target_section_id"><option value="">—</option>@foreach($version->sections as $item)<option value="{{ $item->id }}">{{ $item->localizedTitle('lv') }}</option>@endforeach</select></label><button class="btn">{{ __('messages.save') }}</button>
    </form>
    @foreach($version->conditionalRules as $condition)<div class="list-row"><small>{{ $condition->sourceComponent?->localizedLabel('lv') }} {{ $condition->operator }} {{ data_get($condition->comparison_value,'value') }}</small><form method="POST" action="{{ route('builder.conditions.destroy',[$form,$condition]) }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div>@endforeach
</aside>

<div class="stack">
@foreach($version->sections as $section)
<section class="builder-section">
    <form method="POST" action="{{ route('builder.sections.update',[$form,$section]) }}" class="stack">@csrf @method('PUT')
        <div class="page-header"><h2>{{ $section->localizedTitle('lv') }}</h2><div class="actions"><input type="hidden" name="visible" value="0"><label class="check"><input type="checkbox" name="visible" value="1" @checked($section->visible)> {{ __('messages.visible') }}</label><button class="btn">{{ __('messages.save') }}</button></div></div>
        @include('forms.partials.locale-tabs',['group'=>'section-'.$section->id,'prefix'=>'translations','translations'=>$section->translations,'fields'=>[['name'=>'title','label'=>__('messages.section_title'),'base'=>$section->title,'required'=>true],['name'=>'description','label'=>__('messages.description'),'base'=>$section->description,'type'=>'textarea']]])
    </form>
    <div class="actions mb-3">@foreach(['up'=>'move_up','down'=>'move_down'] as $direction=>$key)<form method="POST" action="{{ route('builder.sections.move',[$form,$section]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="icon-btn" title="{{ __('messages.'.$key) }}">{{ $direction==='up'?'↑':'↓' }}</button></form>@endforeach @if($section->components->isEmpty())<form method="POST" action="{{ route('builder.sections.destroy',[$form,$section]) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form>@endif</div>

    @foreach($section->components as $component)
    @php
        $correct=(array)data_get($component->scoringRule?->rules,'correct',[]);
        $componentFields=[
            ['name'=>'label','label'=>__('messages.label'),'base'=>$component->label,'required'=>true],
            ['name'=>'description','label'=>__('messages.description'),'base'=>$component->description,'type'=>'textarea'],
            ['name'=>'help_text','label'=>__('messages.help_text'),'base'=>$component->help_text],
        ];
        if(in_array($component->type,['short_text','long_text','number','dropdown'])) $componentFields[]=['name'=>'placeholder','label'=>__('messages.placeholder'),'base'=>data_get($component->settings,'placeholder')];
        if($component->type==='consent_checkbox') $componentFields[]=['name'=>'consent_text','label'=>__('messages.consent_text'),'base'=>data_get($component->settings,'consent_text'),'type'=>'textarea'];
        if(in_array($component->type,['rating_scale','linear_scale'])) {$componentFields[]=['name'=>'minimum_label','label'=>__('messages.minimum_label'),'base'=>data_get($component->settings,'minimum_label')];$componentFields[]=['name'=>'maximum_label','label'=>__('messages.maximum_label'),'base'=>data_get($component->settings,'maximum_label')];}
        if($component->type==='image') {$componentFields[]=['name'=>'image_title','label'=>__('messages.image_title'),'base'=>data_get($component->settings,'image_title')];$componentFields[]=['name'=>'image_caption','label'=>__('messages.image_caption'),'base'=>data_get($component->settings,'image_caption'),'type'=>'textarea'];}
    @endphp
    <article class="component-card"><div class="page-header"><div><span class="badge">{{ $component->type }}</span> <strong>{{ $component->localizedLabel(app()->getLocale()) }}</strong></div><div class="actions"><form method="POST" action="{{ route('builder.components.copy',[$form,$component]) }}">@csrf<button class="icon-btn" title="{{ __('messages.copy') }}">⧉</button></form>@foreach(['up','down'] as $direction)<form method="POST" action="{{ route('builder.components.move',[$form,$component]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="icon-btn">{{ $direction==='up'?'↑':'↓' }}</button></form>@endforeach<form method="POST" action="{{ route('builder.components.destroy',[$form,$component]) }}" data-confirm="{{ __('messages.confirm_delete') }}">@csrf @method('DELETE')<button class="icon-btn danger">×</button></form></div></div>
    <form method="POST" action="{{ route('builder.components.update',[$form,$component]) }}" class="stack">@csrf @method('PUT')
        @include('forms.partials.locale-tabs',['group'=>'component-'.$component->id,'prefix'=>'translations','translations'=>$component->translations,'fields'=>$componentFields])
        <div class="form-grid">
            @if(in_array($component->type,['image','file_attachment']))<label>{{ __('messages.attachment') }}<select name="settings[attachment_id]"><option value="">—</option>@foreach($version->attachments as $attachment)<option value="{{ $attachment->id }}" @selected((int)data_get($component->settings,'attachment_id')===$attachment->id)>{{ $attachment->original_name }}</option>@endforeach</select></label>@endif
            <label>{{ __('messages.max_points') }}<input name="max_points" type="number" step="0.01" value="{{ $component->max_points }}"></label>
        </div>
        @if(in_array($component->type,['single_choice','multiple_choice','dropdown']))
            <fieldset data-option-manager data-option-manager-key="component-{{ $component->id }}" data-next-option-index="0" data-max-options="100">
                <legend>{{ __('messages.options') }}</legend>
                <div data-option-list>
                    @foreach($component->options as $option)
                        <div class="option-editor" data-option-row data-option-value="{{ $option->value }}">
                            <div class="page-header"><strong>{{ __('messages.option_number',['number'=>$loop->iteration]) }}</strong><button type="button" class="btn danger" data-option-remove>{{ __('messages.remove_option') }}</button></div>
                            @include('forms.partials.locale-tabs',['group'=>'component-'.$component->id.'-option-'.$option->id,'prefix'=>'options[existing]['.$option->id.'][translations]','translations'=>$option->translations,'fields'=>[['name'=>'label','label'=>__('messages.label'),'base'=>$option->label,'required'=>true]]])
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn" data-option-add>{{ __('messages.add_option') }}</button>
                <template data-option-template>
                    <div class="option-editor" data-option-row>
                        <div class="page-header"><strong>{{ __('messages.new_option') }}</strong><button type="button" class="btn danger" data-option-remove>{{ __('messages.remove_option') }}</button></div>
                        @include('forms.partials.locale-tabs',['group'=>'component-'.$component->id.'-new-option-template','prefix'=>'options[new][__INDEX__][translations]','translations'=>null,'fields'=>[['name'=>'label','label'=>__('messages.label'),'base'=>null]]])
                    </div>
                </template>
            </fieldset>
        @endif
        <label>{{ __('messages.scoring_strategy') }}<select name="scoring_strategy">@foreach(['none','single_choice','multiple_all_or_nothing','multiple_partial','yes_no','numeric_exact','numeric_tolerance','manual'] as $strategy)<option value="{{ $strategy }}" @selected(($component->scoringRule?->strategy??'none')===$strategy)>{{ $strategy }}</option>@endforeach</select></label>
        @if(in_array($component->type,['single_choice','dropdown']))<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach($component->options as $option)<label class="choice"><input type="radio" name="scoring_rules[correct]" value="{{ $option->value }}" @checked(in_array($option->value,$correct,true))> {{ $option->localizedLabel('lv') }}</label>@endforeach</fieldset>
        @elseif($component->type==='multiple_choice')<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach($component->options as $option)<label class="choice"><input type="checkbox" name="scoring_rules[correct][]" value="{{ $option->value }}" @checked(in_array($option->value,$correct,true))> {{ $option->localizedLabel('lv') }}</label>@endforeach</fieldset>
        @elseif($component->type==='yes_no')<fieldset><legend>{{ __('messages.correct_answers') }}</legend>@foreach(['1'=>__('messages.yes'),'0'=>__('messages.no')] as $value=>$label)<label class="choice"><input type="radio" name="scoring_rules[correct]" value="{{ $value }}" @checked((string)data_get($component->scoringRule?->rules,'correct')===$value)> {{ $label }}</label>@endforeach</fieldset>
        @elseif($component->type==='number')<div class="form-grid"><label>{{ __('messages.correct_answers') }}<input type="number" step="any" name="scoring_rules[correct]" value="{{ data_get($component->scoringRule?->rules,'correct') }}"></label><label>{{ __('messages.tolerance') }}<input type="number" min="0" step="any" name="scoring_rules[tolerance]" value="{{ data_get($component->scoringRule?->rules,'tolerance') }}"></label></div>@endif
        <div class="form-grid"><input type="hidden" name="visible" value="0"><label class="check"><input type="checkbox" name="visible" value="1" @checked($component->visible)> {{ __('messages.visible') }}</label><label class="check"><input type="checkbox" name="is_required" value="1" @checked($component->is_required)> {{ __('messages.required') }}</label><label class="check"><input type="checkbox" name="manual_grading" value="1" @checked($component->manual_grading)> {{ __('messages.manual_grading') }}</label>
        <label>{{ __('messages.move_to_section') }}<select name="move_section" data-move-url="{{ route('builder.components.move',[$form,$component]) }}"><option value="">—</option>@foreach($version->sections->where('id','!=',$section->id) as $target)<option value="{{ $target->id }}">{{ $target->localizedTitle('lv') }}</option>@endforeach</select></label></div><button class="btn">{{ __('messages.save') }}</button>
    </form></article>
    @endforeach
</section>
@endforeach
</div></div>
@endunless
@endsection
