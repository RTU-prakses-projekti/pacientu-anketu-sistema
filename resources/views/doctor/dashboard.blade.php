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

<details class="card mb-6" @if($errors->any()) open @endif>
    <summary class="cursor-pointer font-semibold">{{ __('messages.add_patient') }}</summary>
    <form method="POST" action="{{ route('doctor.patients.store', $selectedMembership->organisation) }}" class="form-grid mt-4">@csrf
        <label>{{ __('messages.first_name') }}<input name="first_name" maxlength="100" value="{{ old('first_name') }}"></label>
        <label>{{ __('messages.last_name') }}<input name="last_name" maxlength="100" value="{{ old('last_name') }}"></label>
        <label>{{ __('messages.external_patient_code') }}<input name="external_patient_code" maxlength="100" value="{{ old('external_patient_code') }}"></label>
        <label>{{ __('messages.patient_note') }}<textarea name="note" rows="2" maxlength="10000">{{ old('note') }}</textarea></label>
        <p class="text-sm text-slate-600">{{ __('messages.research_id_generated') }}</p>
        <button class="btn primary" type="submit">{{ __('messages.create_patient') }}</button>
    </form>
</details>

<form id="bulk-selection-form" method="POST" action="{{ route('doctor.questionnaires.bulk.create') }}" class="actions mb-3">@csrf
    <label class="check"><input type="checkbox" data-patient-select-all> {{ __('messages.select_all_visible') }}</label>
    <button class="btn primary" type="submit">{{ __('messages.assign_questionnaire') }}</button>
    <a class="btn" href="{{ route('doctor.patients.export', $selectedMembership->organisation) }}">{{ __('messages.export_answers') }}</a>
</form>

<div class="table-wrap doctor-overview-table"><table><thead><tr>
    <th class="selection-column"><span class="sr-only">{{ __('messages.select_patient') }}</span></th>
    <th>{{ __('messages.slot_number') }}</th><th>{{ __('messages.patient') }}</th><th>{{ __('messages.patient_id') }}</th><th>{{ __('messages.research_id') }}</th><th>{{ __('messages.questionnaires') }}</th><th>{{ __('messages.status') }}</th><th>{{ __('messages.actions') }}</th>
</tr></thead><tbody>
@forelse($patientCases as $patientCase)
@php($notStarted = max(0, $patientCase->assignments_count - $patientCase->completed_assignments_count - $patientCase->in_progress_assignments_count))
<tr data-patient-row="{{ $patientCase->public_id }}">
    <td class="selection-column"><input type="checkbox" form="bulk-selection-form" name="patient_case_ids[]" value="{{ $patientCase->id }}" data-patient-select aria-label="{{ __('messages.select_patient') }} {{ $patientCase->slot_number }}"></td>
    <td>{{ $patientCase->slot_number }}</td>
    <td><strong>{{ trim($patientCase->first_name.' '.$patientCase->last_name) ?: '—' }}</strong></td>
    <td>{{ $patientCase->external_patient_code ?: '—' }}</td>
    <td><code>{{ $patientCase->patient_code }}</code></td>
    <td>{{ trans_choice('messages.assigned_count', $patientCase->assignments_count, ['count' => $patientCase->assignments_count]) }}</td>
    <td><span class="patient-summary-status">{{ __('messages.completed_count', ['count' => $patientCase->completed_assignments_count]) }}</span><br><span class="text-sm text-slate-600">{{ __('messages.in_progress_count', ['count' => $patientCase->in_progress_assignments_count]) }} · {{ __('messages.not_started_count', ['count' => $notStarted]) }}</span></td>
    <td class="doctor-row-actions">
        <details class="relative"><summary class="btn">{{ __('messages.edit') }}</summary>
            <form method="POST" action="{{ route('doctor.patients.slots.update', [$selectedMembership->organisation, $selectedMembership->user, $patientCase->slot_number]) }}" class="stack doctor-inline-edit">@csrf @method('PUT')
                <label>{{ __('messages.first_name') }}<input name="first_name" maxlength="100" value="{{ $patientCase->first_name }}"></label>
                <label>{{ __('messages.last_name') }}<input name="last_name" maxlength="100" value="{{ $patientCase->last_name }}"></label>
                <label>{{ __('messages.external_patient_code') }}<input name="external_patient_code" maxlength="100" value="{{ $patientCase->external_patient_code }}"></label>
                <label>{{ __('messages.patient_note') }}<textarea name="note" rows="2" maxlength="10000">{{ $patientCase->note }}</textarea></label>
                <button class="btn primary">{{ __('messages.save') }}</button>
            </form>
        </details>
        <a class="btn" href="{{ route('doctor.questionnaires.index', $patientCase) }}">{{ __('messages.questionnaires') }}</a>
    </td>
</tr>
@empty
<tr><td colspan="8">{{ __('messages.no_patients') }}</td></tr>
@endforelse
</tbody></table></div>
{{ $patientCases->links() }}
@endif
@endsection
@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-patient-select-all]').forEach((selectAll) => {
        if (selectAll.dataset.selectAllBound === '1') return;
        selectAll.dataset.selectAllBound = '1';
        const patients = [...document.querySelectorAll('[data-patient-select]')];
        const refresh = () => {
            const selected = patients.filter((checkbox) => checkbox.checked).length;
            selectAll.checked = patients.length > 0 && selected === patients.length;
            selectAll.indeterminate = selected > 0 && selected < patients.length;
        };
        selectAll.addEventListener('change', () => {
            patients.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
            refresh();
        });
        patients.forEach((checkbox) => checkbox.addEventListener('change', refresh));
        refresh();
    });
})();
</script>
@endpush
