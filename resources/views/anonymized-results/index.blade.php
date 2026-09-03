@extends('layouts.app')
@section('title', __('messages.anonymized_results'))
@section('content')
<div class="page-header"><h1>{{ __('messages.anonymized_results') }}</h1></div>
<form method="POST" action="{{ route('anonymized-results.export') }}" class="stack mb-6" id="anonymized-results-export-form">
    @csrf
    <div class="actions"><button type="button" class="btn" data-select-all>{{ __('messages.select_all_visible') }}</button><label>{{ __('messages.export_format') }} <select name="format"><option value="csv">CSV</option><option value="xlsx">XLSX</option></select></label><button class="btn primary">{{ __('messages.export_selected') }}</button></div>
    <div class="table-wrap"><table><thead><tr><th></th><th>{{ __('messages.research_id') }}</th><th>{{ __('messages.form') }}</th><th>{{ __('messages.submitted') }}</th><th>{{ __('messages.handed_off') }}</th><th></th></tr></thead><tbody>
    @forelse($handoffs as $handoff)<tr><td><input type="checkbox" name="handoff_ids[]" value="{{ $handoff->public_id }}" data-handoff-checkbox></td><td><code>{{ $handoff->assignment->patientCase->patient_code }}</code></td><td>{{ $handoff->submission->publication->form->name }}</td><td>{{ $handoff->submission->submitted_at }}</td><td>{{ $handoff->handed_off_at }}</td><td><a class="btn" href="{{ route('anonymized-results.show', $handoff) }}">{{ __('messages.view_anonymized_result') }}</a></td></tr>@empty<tr><td colspan="6">{{ __('messages.no_anonymized_results') }}</td></tr>@endforelse
    </tbody></table></div>
</form>
{{ $handoffs->links() }}
@endsection
@push('scripts')
<script>
document.querySelector('[data-select-all]')?.addEventListener('click', () => document.querySelectorAll('[data-handoff-checkbox]').forEach((checkbox) => { checkbox.checked = true; }));
</script>
@endpush
