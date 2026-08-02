@php
    $locales = config('form_locales.supported');
    $defaultLocale = config('form_locales.default');
    $tabGroup = \Illuminate\Support\Str::slug($group).'-'.substr(md5($prefix), 0, 8);
@endphp
<div class="locale-editor" data-locale-editor>
    <div class="locale-tabs" role="tablist" aria-label="{{ __('messages.content_language') }}">
        @foreach($locales as $localeCode)
            @php
                $hasContent = collect($fields)->contains(function ($field) use ($translations, $localeCode, $defaultLocale) {
                    $value = data_get($translations, $localeCode.'.'.$field['name']);
                    if ($localeCode === $defaultLocale && (!is_string($value) || trim($value) === '')) $value = $field['base'] ?? null;
                    return is_string($value) ? trim($value) !== '' : $value !== null;
                });
            @endphp
            <button type="button" role="tab" class="locale-tab {{ $loop->first ? 'is-active' : '' }}" data-locale-tab="{{ $localeCode }}" aria-controls="{{ $tabGroup }}-{{ $localeCode }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                <strong>{{ strtoupper($localeCode) }}</strong>
                <span data-locale-status data-filled-label="{{ __('messages.translation_filled') }}" data-empty-label="{{ $localeCode === $defaultLocale ? __('messages.translation_empty') : __('messages.translation_lv_fallback') }}">{{ $hasContent ? __('messages.translation_filled') : ($localeCode === $defaultLocale ? __('messages.translation_empty') : __('messages.translation_lv_fallback')) }}</span>
            </button>
        @endforeach
    </div>
    @foreach($locales as $localeCode)
        <div id="{{ $tabGroup }}-{{ $localeCode }}" class="locale-panel" role="tabpanel" data-locale-panel="{{ $localeCode }}" @if(!$loop->first) hidden @endif>
            @foreach($fields as $field)
                @php
                    $value = data_get($translations, $localeCode.'.'.$field['name']);
                    if ($localeCode === $defaultLocale && (!is_string($value) || trim($value) === '')) $value = $field['base'] ?? null;
                    $inputName = $prefix.'['.$localeCode.']['.$field['name'].']';
                @endphp
                <div @if(isset($field['setting'])) data-setting="{{ $field['setting'] }}" @endif>
                    <label>{{ $field['label'] }}
                        @if(($field['type'] ?? 'input') === 'textarea')
                            <textarea name="{{ $inputName }}" @required($localeCode === $defaultLocale && ($field['required'] ?? false))>{{ old(str_replace(['[', ']'], ['.', ''], $inputName), $value) }}</textarea>
                        @else
                            <input name="{{ $inputName }}" value="{{ old(str_replace(['[', ']'], ['.', ''], $inputName), $value) }}" @required($localeCode === $defaultLocale && ($field['required'] ?? false))>
                        @endif
                    </label>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
