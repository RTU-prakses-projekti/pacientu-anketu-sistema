@php($locale=app()->getLocale())
@php($label=data_get($component->translations,"$locale.label",$component->label))
<div class="form-component" data-component="{{ $component->id }}" data-default-visible="{{ $component->visible ? 1 : 0 }}" @if(!$component->visible)hidden @endif>
@if($component->type==='form_title')<h1>{{ $label }}</h1>
@elseif($component->type==='heading')<h3>{{ $label }}</h3>
@elseif($component->type==='explanatory_text')<div class="prose"><strong>{{ $label }}</strong><p>{{ data_get($component->translations,"$locale.description",$component->description) }}</p></div>
@elseif(in_array($component->type,['image','file_attachment']))<p><strong>{{ $label }}</strong></p>@if(data_get($component->settings,'attachment_id'))@php($attachmentUrl=isset($submission) ? route('submissions.attachments.download',[$submission,data_get($component->settings,'attachment_id')]) : route('attachments.download',data_get($component->settings,'attachment_id')))@if($component->type==='image')<img src="{{ $attachmentUrl }}" alt="{{ $label }}" class="max-w-full rounded">@else<a class="btn" href="{{ $attachmentUrl }}">{{ __('messages.download') }}</a>@endif @endif
@else
<fieldset><legend>{{ $label }} @if($component->is_required)<span aria-hidden="true">*</span>@endif</legend>@if($component->description)<p>{{ $component->description }}</p>@endif @if($component->help_text)<p class="help">{{ $component->help_text }}</p>@endif
@php($name="answers[$component->id]") @php($value=$answers[$component->id]??data_get($component->settings,'default_value'))
@switch($component->type)
@case('short_text')<input data-answer name="{{ $name }}" value="{{ $value }}" placeholder="{{ data_get($component->settings,'placeholder') }}" @required($component->is_required)>@break
@case('long_text')<textarea data-answer name="{{ $name }}" placeholder="{{ data_get($component->settings,'placeholder') }}" @required($component->is_required)>{{ $value }}</textarea>@break
@case('number')<input data-answer type="number" step="any" name="{{ $name }}" value="{{ $value }}" min="{{ data_get($component->settings,'minimum') }}" max="{{ data_get($component->settings,'maximum') }}" @required($component->is_required)>@break
@case('date')<input data-answer type="date" name="{{ $name }}" value="{{ $value }}" @required($component->is_required)>@break
@case('time')<input data-answer type="time" name="{{ $name }}" value="{{ $value }}" @required($component->is_required)>@break
@case('yes_no')@foreach(['1'=>__('messages.yes'),'0'=>__('messages.no')] as $v=>$text)<label class="choice"><input data-answer type="radio" name="{{ $name }}" value="{{ $v }}" @checked((string)$value===$v)> {{ $text }}</label>@endforeach @break
@case('single_choice')@foreach($component->options as $option)<label class="choice"><input data-answer type="radio" name="{{ $name }}" value="{{ $option->value }}" @checked((string)$value===(string)$option->value)> {{ data_get($option->translations,"$locale.label",$option->label) }}</label>@endforeach @break
@case('multiple_choice')@foreach($component->options as $option)<label class="choice"><input data-answer type="checkbox" name="{{ $name }}[]" value="{{ $option->value }}" @checked(in_array($option->value,(array)$value,true))> {{ data_get($option->translations,"$locale.label",$option->label) }}</label>@endforeach @break
@case('dropdown')<select data-answer name="{{ $name }}" @required($component->is_required)><option value="">—</option>@foreach($component->options as $option)<option value="{{ $option->value }}" @selected((string)$value===(string)$option->value)>{{ data_get($option->translations,"$locale.label",$option->label) }}</option>@endforeach</select>@break
@case('rating_scale') @case('linear_scale')<input data-answer type="range" name="{{ $name }}" value="{{ $value??data_get($component->settings,'minimum',1) }}" min="{{ data_get($component->settings,'minimum',1) }}" max="{{ data_get($component->settings,'maximum',5) }}"><output>{{ $value??data_get($component->settings,'minimum',1) }}</output>@break
@case('consent_checkbox')<label class="choice consent"><input data-answer type="checkbox" name="{{ $name }}" value="1" @checked((bool)$value) @required($component->is_required)> {{ data_get($component->settings,'consent_text',$component->description?:$label) }}</label>@break
@endswitch</fieldset>
@endif
</div>
