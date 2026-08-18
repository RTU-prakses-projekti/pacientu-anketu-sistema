@extends('layouts.app')
@section('title', __('messages.bulk_assign_questionnaire'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.bulk_assign_questionnaire') }}</h1><p>{{ trans_choice('messages.selected_patients_count', $patientCases->count(), ['count' => $patientCases->count()]) }}</p></div><a class="btn" href="{{ route('doctor.dashboard', ['organisation_id' => $patientCases->first()->organisation_id]) }}">{{ __('messages.back') }}</a></div>
<section class="card mb-6"><h2>{{ __('messages.selected_patients') }}</h2><ul class="list-disc pl-6">@foreach($patientCases as $patientCase)<li>{{ $patientCase->slot_number }}. {{ trim($patientCase->first_name.' '.$patientCase->last_name) ?: $patientCase->patient_code }} · <code>{{ $patientCase->patient_code }}</code></li>@endforeach</ul></section>
<section class="card"><h2>{{ __('messages.assign_questionnaire') }}</h2>
@if($publications->isEmpty())
<p>{{ __('messages.no_available_questionnaires') }}</p>
@else
<form method="POST" action="{{ route('doctor.questionnaires.bulk.store') }}" class="stack">@csrf
    @foreach($patientCases as $patientCase)<input type="hidden" name="patient_case_ids[]" value="{{ $patientCase->id }}">@endforeach
    <label>{{ __('messages.publication') }}<select name="publication_id" required>@foreach($publications as $publication)<option value="{{ $publication->id }}">{{ $publication->name }} · {{ $publication->formVersion->localizedTitle() }}</option>@endforeach</select></label>
    <p class="text-sm text-slate-600">{{ __('messages.bulk_assignment_help') }}</p>
    <button class="btn primary">{{ __('messages.assign') }}</button>
</form>
@endif
</section>
@endsection
