@extends('layouts.app')
@section('title', __('messages.anonymized_results'))
@section('content')
<div class="page-header"><h1>{{ __('messages.anonymized_results') }}</h1></div>
<div class="table-wrap"><table><thead><tr><th>{{ __('messages.research_id') }}</th><th>{{ __('messages.form') }}</th><th>{{ __('messages.submitted') }}</th><th>{{ __('messages.handed_off') }}</th><th></th></tr></thead><tbody>
@forelse($handoffs as $handoff)<tr><td><code>{{ $handoff->assignment->patientCase->patient_code }}</code></td><td>{{ $handoff->submission->publication->form->name }}</td><td>{{ $handoff->submission->submitted_at }}</td><td>{{ $handoff->handed_off_at }}</td><td><a class="btn" href="{{ route('anonymized-results.show', $handoff) }}">{{ __('messages.view_anonymized_result') }}</a></td></tr>@empty<tr><td colspan="5">{{ __('messages.no_anonymized_results') }}</td></tr>@endforelse
</tbody></table></div>
{{ $handoffs->links() }}
@endsection
