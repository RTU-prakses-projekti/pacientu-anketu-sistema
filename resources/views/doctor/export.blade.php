@extends('layouts.app')
@section('title', __('messages.export_answers'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.export_answers') }}</h1><p class="text-slate-600">{{ $organisation->name }}</p></div></div>
<form method="POST" action="{{ route('doctor.patients.export.download', $organisation) }}" class="card form-grid">
    @csrf
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
