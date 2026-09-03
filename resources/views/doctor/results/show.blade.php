@extends('layouts.app')
@section('title', __('messages.read_only_result'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.read_only_result') }}</h1><p>{{ $patientCase->first_name }} {{ $patientCase->last_name }} · <code>{{ $patientCase->patient_code }}</code> · {{ $assignment->label }}</p></div><a class="btn" href="{{ route('doctor.dashboard', ['organisation_id'=>$patientCase->organisation_id,'doctor_id'=>$patientCase->doctor_id]) }}">{{ __('messages.back') }}</a></div>
<div class="card mb-6"><p><strong>{{ __('messages.questionnaire') }}:</strong> {{ $submission->publication->form->name }}</p><p><strong>{{ __('messages.submission_status') }}:</strong> <span class="badge">{{ __('messages.submission_status_'.$submission->status) }}</span></p><p><strong>{{ __('messages.submitted') }}:</strong> {{ $submission->submitted_at }}</p></div>
<div class="card mb-6"><h2>{{ __('messages.hand_off_result') }}</h2><form method="POST" action="{{ route('doctor.results.handoff', [$patientCase, $assignment]) }}" class="actions">@csrf<label>{{ __('messages.recipient') }}<select name="recipient" required><option value="">—</option>@foreach($recipients as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}@foreach($recipient->memberships->flatMap->roles->unique('id') as $role) — {{ $role->display_name }}@endforeach</option>@endforeach</select></label><button class="btn primary">{{ __('messages.hand_off_result') }}</button></form></div>
<section class="stack" aria-label="{{ __('messages.questionnaire_parts') }}">
@forelse($submission->answers as $answer)<article class="card"><h2>{{ $answer->component->localizedLabel() }}</h2><p><strong>{{ __('messages.answer') }}:</strong> {{ $answer->component->localizedAnswerValue($answer->value) ?: '—' }}</p></article>@empty<div class="notice">{{ __('messages.no_records') }}</div>@endforelse
</section>
@endsection
