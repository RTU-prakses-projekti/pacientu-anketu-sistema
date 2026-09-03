@extends('layouts.app')
@section('title', __('messages.bulk_links_ready'))
@section('content')
<div class="page-header"><div><h1>{{ __('messages.bulk_links_ready') }}</h1><p>{{ __('messages.bulk_links_copy_now') }}</p></div><a class="btn" href="{{ route('doctor.dashboard', ['organisation_id' => $organisationId]) }}">{{ __('messages.back') }}</a></div>
<section class="card"><div class="actions mb-4"><button type="button" class="btn" data-copy-all>{{ __('messages.copy_all_links') }}</button></div><div class="table-wrap"><table><thead><tr><th>{{ __('messages.patient') }}</th><th>{{ __('messages.research_id') }}</th><th>{{ __('messages.secure_patient_link') }}</th><th></th></tr></thead><tbody>@foreach($links as $index => $link)<tr><td>{{ $link['name'] }}</td><td><code>{{ $link['patient_code'] }}</code></td><td><input id="patient-link-{{ $index }}" readonly value="{{ $link['url'] }}" data-patient-link></td><td><button type="button" class="btn primary" data-copy-target="patient-link-{{ $index }}">{{ __('messages.copy_link') }}</button></td></tr>@endforeach</tbody></table></div></section>
@endsection
@push('scripts')
<script>
document.querySelector('[data-copy-all]')?.addEventListener('click', async () => { const text = [...document.querySelectorAll('[data-patient-link]')].map((input) => input.value).join('\n'); try { if (!navigator.clipboard) throw new Error('clipboard unavailable'); await navigator.clipboard.writeText(text); } catch { const fallback = document.createElement('textarea'); fallback.value = text; fallback.style.position = 'fixed'; fallback.style.opacity = '0'; document.body.appendChild(fallback); fallback.select(); document.execCommand('copy'); fallback.remove(); } });
</script>
@endpush
