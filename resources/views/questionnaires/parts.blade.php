@extends('layouts.app')
@section('title', __('messages.add_questionnaire_part_from_git'))
@section('content')
<div class="page-header"><div><a href="{{ route('forms.builder',$form) }}">{{ __('messages.back') }}</a><h1>{{ __('messages.add_questionnaire_part_from_git') }}</h1><p>{{ $form->name }}</p></div></div>
<div class="stack">
@forelse($packages as $package)
<article class="card"><div class="page-header"><div><h2>{{ $package['name'] }}</h2><p><code>{{ $package['package_name'] }}</code></p></div><span class="badge">schema v{{ $package['schema_version'] }}</span></div>
<p>{{ __('messages.sections') }}: {{ $package['sections'] }} · {{ __('messages.components_count') }}: {{ $package['components'] }} · {{ __('messages.assets') }}: {{ $package['has_assets'] ? __('messages.yes') : __('messages.no') }}</p>
@if($package['duplicate'])<p class="help">{{ __('messages.questionnaire_imported_as_separate_form') }}</p>@endif
@if($package['part_duplicate'])
<div class="notice">{{ __('messages.questionnaire_part_already_imported') }}</div>
@else
<form method="POST" action="{{ route('questionnaires.import-part',[$form,$version]) }}">@csrf<input type="hidden" name="package_name" value="{{ $package['package_name'] }}"><button class="btn primary">{{ __('messages.import_as_next_part') }}</button></form>
@endif
</article>
@empty<div class="notice">{{ __('messages.no_questionnaire_packages') }}</div>@endforelse
</div>
@endsection
