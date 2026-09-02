@extends('layouts.app')
@section('title', __('messages.export_answers'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.export_answers') }}</h1><p class="text-slate-600">{{ $organisation->name }}</p></div><a class="btn" href="{{ route('doctor.dashboard', ['organisation_id' => $organisation->id]) }}">{{ __('messages.back') }}</a></div>
<form method="POST" action="{{ route('doctor.patients.export.download', $organisation) }}" class="card form-grid">
    @csrf
    @foreach($patientCaseIds as $patientCaseId)<input type="hidden" name="patient_case_ids[]" value="{{ $patientCaseId }}">@endforeach
    <label>{{ __('messages.format') }}
        <select name="format">
            <option value="csv">CSV</option>
            <option value="xlsx">XLSX</option>
        </select>
    </label>
    <label class="check"><input type="checkbox" name="anonymize" value="1" checked> {{ __('messages.redact_patient_names') }}</label>
    <button class="btn primary" type="submit">{{ __('messages.export_answers') }}</button>
</form>
@endsection
