@php
    $contentLocale = $contentLocale ?? app()->getLocale();
    $showFallbackIndicators = $showFallbackIndicators ?? false;
    $label = $component->localizedLabel($contentLocale);
    $description = $component->localizedDescription($contentLocale);
    $helpText = $component->localizedHelpText($contentLocale);
@endphp
<div class="form-component" data-component="{{ $component->id }}" data-default-visible="{{ $component->visible ? 1 : 0 }}" @if(!$component->visible)hidden @endif>
@if($showFallbackIndicators && $component->usesContentFallback($contentLocale))<span class="fallback-indicator">{{ __('messages.fallback_used') }}</span>@endif
@if($component->type==='form_title')<h1>{{ $label }}</h1>
@elseif($component->type==='heading')<h3>{{ $label }}</h3>
@elseif($component->type==='explanatory_text')<div class="prose"><strong class="multiline-text" style="white-space:pre-wrap">{{ $label }}</strong>@if($description)<p class="multiline-text" style="white-space:pre-wrap">{{ $description }}</p>@endif</div>
@elseif(in_array($component->type,['image','file_attachment']))
    @php($mediaTitle=$component->type==='image'?$component->localizedImageTitle($contentLocale):$label)
    @if($mediaTitle)<p><strong>{{ $mediaTitle }}</strong></p>@endif
    @if(data_get($component->settings,'attachment_id'))
        @php($attachmentUrl=isset($submission) ? route('submissions.attachments.download',[$submission,data_get($component->settings,'attachment_id')]) : route('attachments.download',data_get($component->settings,'attachment_id')))
        @if($component->type==='image')<figure><img src="{{ $attachmentUrl }}" alt="{{ $mediaTitle }}" class="max-w-full rounded">@if($component->localizedImageCaption($contentLocale))<figcaption>{{ $component->localizedImageCaption($contentLocale) }}</figcaption>@endif</figure>@else<a class="btn" href="{{ $attachmentUrl }}">{{ __('messages.download', [], $contentLocale) }}</a>@endif
    @endif
@else
<fieldset><legend>{{ $label }} @if($component->is_required)<span aria-hidden="true">*</span>@endif</legend>@if($description)<p class="multiline-text" style="white-space:pre-wrap">{{ $description }}</p>@endif @if($helpText)<p class="help multiline-text" style="white-space:pre-wrap">{{ $helpText }}</p>@endif
@php($name="answers[$component->id]") @php($value=$answers[$component->id]??data_get($component->settings,'default_value'))
@switch($component->type)
@case('short_text')<input data-answer name="{{ $name }}" value="{{ $value }}" placeholder="{{ $component->localizedPlaceholder($contentLocale) }}" @required($component->is_required)>@break
@case('long_text')<textarea data-answer name="{{ $name }}" placeholder="{{ $component->localizedPlaceholder($contentLocale) }}" @required($component->is_required)>{{ $value }}</textarea>@break
@case('number')<input data-answer type="number" step="any" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $component->localizedPlaceholder($contentLocale) }}" min="{{ data_get($component->settings,'minimum') }}" max="{{ data_get($component->settings,'maximum') }}" @required($component->is_required)>@break
@case('date')<input data-answer type="date" name="{{ $name }}" value="{{ $value }}" @required($component->is_required)>@break
@case('time')<input data-answer type="time" name="{{ $name }}" value="{{ $value }}" @required($component->is_required)>@break
@case('yes_no')@foreach(['1'=>__('messages.yes', [], $contentLocale),'0'=>__('messages.no', [], $contentLocale)] as $v=>$text)<label class="choice"><input data-answer type="radio" name="{{ $name }}" value="{{ $v }}" @checked((string)$value===$v)> {{ $text }}</label>@endforeach @break
@case('single_choice')@foreach($component->options as $option)<label class="choice"><input data-answer type="radio" name="{{ $name }}" value="{{ $option->value }}" @checked((string)$value===(string)$option->value)> {{ $option->localizedLabel($contentLocale) }}</label>@endforeach @break
@case('multiple_choice')@foreach($component->options as $option)<label class="choice"><input data-answer type="checkbox" name="{{ $name }}[]" value="{{ $option->value }}" @checked(in_array($option->value,(array)$value,true))> {{ $option->localizedLabel($contentLocale) }}</label>@endforeach @break
@case('dropdown')<select data-answer name="{{ $name }}" @required($component->is_required)><option value="">—</option>@foreach($component->options as $option)<option value="{{ $option->value }}" @selected((string)$value===(string)$option->value)>{{ $option->localizedLabel($contentLocale) }}</option>@endforeach</select>@break
@case('rating_scale') @case('linear_scale')
@php($rangeMinimum=data_get($component->settings,'minimum',1))
@php($rangeMaximum=data_get($component->settings,'maximum',5))
@php($rangeValue=$value??$rangeMinimum)
@php($rangeId='range-'.$component->id)
<div class="scale-labels"><span>{{ $component->localizedMinimumLabel($contentLocale) }}</span><span>{{ $component->localizedMaximumLabel($contentLocale) }}</span></div><div data-range-control><input data-answer data-range-input class="scale-range" type="range" id="{{ $rangeId }}" name="{{ $name }}" value="{{ $rangeValue }}" min="{{ $rangeMinimum }}" max="{{ $rangeMaximum }}" step="1" aria-describedby="{{ $rangeId }}-value"><p class="scale-current">{{ __('messages.selected_value') }}: <output data-range-output id="{{ $rangeId }}-value" for="{{ $rangeId }}">{{ $rangeValue }}</output></p></div>@break
@case('consent_checkbox')<label class="choice consent"><input data-answer type="checkbox" name="{{ $name }}" value="1" @checked((bool)$value) @required($component->is_required)> {{ $component->localizedConsentText($contentLocale) }}</label>@break
@endswitch</fieldset>
@endif
</div>
