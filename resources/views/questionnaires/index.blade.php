@extends('layouts.app')
@section('title', __('messages.import_questionnaire'))
@section('content')
<div class="page-header"><div><a href="{{ route('forms.index',$organisation) }}">{{ __('messages.back') }}</a><h1>{{ __('messages.import_questionnaire') }}</h1><p>{{ $organisation->name }}</p></div></div>
<form method="POST" action="{{ route('questionnaires.import-file', $organisation) }}" enctype="multipart/form-data" class="card mb-6">@csrf<label>{{ __('messages.questionnaire_file') }}<input type="file" name="package_file" accept=".zip,application/zip" required></label><button class="btn primary mt-4">{{ __('messages.import_questionnaire_file') }}</button></form>
<div class="stack">
@forelse($packages as $package)
<article class="card"><div class="page-header"><div><h2>{{ $package['name'] }}</h2><p><code>{{ $package['package_name'] }}</code></p><p class="text-sm text-slate-600">SHA-256: <code>{{ $package['content_hash'] }}</code></p></div><span class="badge">schema v{{ $package['schema_version'] }}</span></div>
<p>{{ __('messages.sections') }}: {{ $package['sections'] }} · {{ __('messages.components_count') }}: {{ $package['components'] }} · {{ __('messages.assets') }}: {{ $package['has_assets'] ? __('messages.yes') : __('messages.no') }}</p>
@if($package['duplicate'])<div class="notice">{{ __('messages.questionnaire_already_imported') }}</div>@else<form method="POST" action="{{ route('questionnaires.import',$organisation) }}">@csrf<input type="hidden" name="package_name" value="{{ $package['package_name'] }}"><button class="btn primary">{{ __('messages.import_as_draft') }}</button></form>@endif
</article>
@empty<div class="notice">{{ __('messages.no_questionnaire_packages') }}</div>@endforelse
</div>
@endsection
