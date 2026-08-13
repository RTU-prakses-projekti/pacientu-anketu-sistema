@extends('layouts.patient')
@section('title', __('messages.patient_questionnaire_portal'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.your_questionnaires') }}</h1><p class="text-slate-600">{{ __('messages.complete_parts_in_order') }}</p></div></div>
@if($surveyEnded)<div class="notice warning mb-4">{{ __('messages.survey_ended_no_consent') }}</div>@endif
<div class="stack">
@forelse($parts as $index => $part)
@php($assignment=$part['assignment'])
<article class="card flex flex-wrap items-center justify-between gap-4" data-part-status="{{ $part['status'] }}">
    <div><span class="badge">{{ __('messages.part_number', ['number' => $index + 1]) }}</span><h2 class="mb-1 mt-2">{{ $assignment->label }}</h2><p class="text-sm text-slate-600">{{ __('messages.'.$part['status']) }}</p></div>
    @if($part['status']==='completed')<span class="badge patient-status-complete">{{ __('messages.completed_status') }}</span>
    @elseif($part['unlocked'])<form method="POST" action="{{ route('patient.assignments.start', [$patientAccessPackage, $assignment]) }}">@csrf<button class="btn primary">{{ $part['status']==='in_progress' ? __('messages.continue') : __('messages.start') }}</button></form>
    @else<span class="badge">{{ __('messages.locked_until_previous') }}</span>@endif
</article>
@empty<div class="notice">{{ __('messages.no_questionnaires_assigned') }}</div>@endforelse
</div>
@if($parts->isNotEmpty() && $parts->every(fn($part) => $part['status']==='completed'))<div class="notice success mt-5" role="status">{{ __('messages.all_parts_completed') }}</div>@endif
@endsection
