@extends('layouts.app')
@section('title', __('messages.doctor_dashboard'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.doctor_dashboard') }}</h1><p class="text-slate-600">{{ __('messages.patient_registry') }}</p></div></div>
@if($workspaces->isEmpty())
<div class="notice">{{ __('messages.no_doctor_workspaces') }}</div>
@else
@if($workspaces->count() > 1)
<form method="GET" action="{{ route('doctor.dashboard') }}" class="filters">
    <label>{{ __('messages.select_workspace') }}
        <select name="workspace" onchange="const ids=this.value.split(':');this.form.organisation_id.value=ids[0];this.form.doctor_id.value=ids[1];this.form.submit()">
            @foreach($workspaces as $membership)<option value="{{ $membership->organisation_id }}:{{ $membership->user_id }}" @selected($selectedMembership?->id === $membership->id)>{{ $membership->organisation->name }} — {{ $membership->user->name }}</option>@endforeach
        </select>
    </label>
    <input type="hidden" name="organisation_id" value="{{ $selectedMembership?->organisation_id }}"><input type="hidden" name="doctor_id" value="{{ $selectedMembership?->user_id }}">
    <noscript><button class="btn">{{ __('messages.view') }}</button></noscript>
</form>
@endif
<div class="mb-4 text-sm text-slate-600"><strong>{{ $selectedMembership->organisation->name }}</strong> · {{ $selectedMembership->user->name }}</div>
<div data-doctor-scroll-container>
<p class="doctor-scroll-hint">{{ __('messages.horizontal_scroll_hint') }}</p>
<div class="doctor-table-top-scroll" data-doctor-scroll-top role="region" aria-label="{{ __('messages.horizontal_scroll_hint') }}" tabindex="0"><div class="doctor-table-top-scroll-spacer" data-doctor-scroll-spacer></div></div>
<div class="table-wrap doctor-table" data-horizontal-scroll="true" data-doctor-scroll-bottom><table><thead><tr>
    <th class="slot-column">{{ __('messages.slot_number') }}</th><th class="name-column">{{ __('messages.first_name') }}</th><th class="name-column">{{ __('messages.last_name') }}</th><th class="patient-id-column">{{ __('messages.patient_id') }}</th><th class="research-id-column">{{ __('messages.research_id') }}</th><th class="note-column">{{ __('messages.patient_note') }}</th><th class="actions-column">{{ __('messages.actions') }}</th>
    @foreach($columns as $column)<th class="status-column">{{ $column->label ?: $column->publication->formVersion->title }}</th>@endforeach
</tr></thead><tbody>
@foreach($slots as $slot)
@php($patientCase = $patientCases->get($slot))
<tr data-slot="{{ $slot }}"><td class="slot-column">{{ $slot }}</td>
<td class="name-column"><input class="name-input" form="patient-slot-{{ $slot }}" name="first_name" value="{{ $patientCase?->first_name }}" aria-label="{{ __('messages.first_name') }} {{ $slot }}"></td>
<td class="name-column"><input class="name-input" form="patient-slot-{{ $slot }}" name="last_name" value="{{ $patientCase?->last_name }}" aria-label="{{ __('messages.last_name') }} {{ $slot }}"></td>
<td class="patient-id-column"><input class="patient-id-input" form="patient-slot-{{ $slot }}" name="external_patient_code" value="{{ $patientCase?->external_patient_code }}" aria-label="{{ __('messages.patient_id') }} {{ $slot }}"></td>
<td class="research-id-column"><code>{{ $patientCase?->patient_code ?? '—' }}</code></td>
<td class="note-column"><textarea class="patient-note-input" form="patient-slot-{{ $slot }}" name="note" rows="2" aria-label="{{ __('messages.patient_note') }} {{ $slot }}">{{ $patientCase?->note }}</textarea></td>
<td class="actions-column"><form id="patient-slot-{{ $slot }}" method="POST" action="{{ route('doctor.patients.slots.update', [$selectedMembership->organisation, $selectedMembership->user, $slot]) }}">@csrf @method('PUT')<button class="btn" type="submit">{{ $patientCase ? __('messages.save_note') : __('messages.create_patient') }}</button></form>@if($patientCase)<a class="btn mt-2" href="{{ route('doctor.questionnaires.index',$patientCase) }}">{{ __('messages.questionnaires') }}</a>@endif</td>
@foreach($columns as $column)
@php($assignment = $patientCase?->assignments->firstWhere('publication_id', $column->publication_id))
@php($completedSubmission = $assignment?->completedSubmission)
<td class="status-column">@if($completedSubmission)<a class="status-square completed" data-status="completed" href="{{ route('doctor.results.show', [$patientCase, $assignment]) }}" title="{{ __('messages.completed_status') }}" aria-label="{{ __('messages.completed_status') }}: {{ $column->label }}">✓</a>@else<span class="status-square not-completed" data-status="not-completed" title="{{ __('messages.not_completed_status') }}" aria-label="{{ __('messages.not_completed_status') }}: {{ $column->label }}">—</span>@endif</td>
@endforeach
</tr>
@endforeach
</tbody></table></div>
</div>
@endif
@endsection
