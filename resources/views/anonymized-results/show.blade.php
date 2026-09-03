@extends('layouts.app')
@section('title', __('messages.anonymized_results'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.anonymized_results') }}</h1><p><code>{{ $patientCode }}</code> · {{ $formName }}</p></div><a class="btn" href="{{ route('anonymized-results.index') }}">{{ __('messages.back') }}</a></div>
<div class="card mb-6"><p><strong>{{ __('messages.submitted') }}:</strong> {{ $submittedAt }}</p><p><strong>{{ __('messages.handed_off') }}:</strong> {{ $handedOffAt }}</p></div>
<section class="stack" aria-label="{{ __('messages.questionnaire_parts') }}">
@forelse($answers as $answer)<article class="card"><h2>{{ $answer->component->localizedLabel() }}</h2><p><strong>{{ __('messages.answer') }}:</strong> {{ $answer->component->localizedAnswerValue($answer->value) ?: '—' }}</p></article>@empty<div class="notice">{{ __('messages.no_records') }}</div>@endforelse
</section>
@endsection
