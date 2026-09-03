@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.organisations') }}</h1><a class="btn primary" href="{{ route('organisations.create') }}">{{ __('messages.new_organisation') }}</a></div>
<div class="table-wrap"><table><thead><tr><th>{{ __('messages.name') }}</th><th>{{ __('messages.members') }}</th><th>{{ __('messages.questionnaires_label') }}</th><th>{{ __('messages.actions') }}</th></tr></thead><tbody>
@foreach($organisations as $organisation)
    <tr><td>{{ $organisation->name }}</td><td>{{ $organisation->memberships_count }}</td><td>{{ $organisation->forms_count }}</td><td>
        <div class="actions">
            <a href="{{ route('organisations.edit', $organisation) }}">{{ __('messages.edit') }}</a>
            <a href="{{ route('forms.index', $organisation) }}">{{ __('messages.questionnaires_label') }}</a>
            @if($deleteEligibility[$organisation->id]['allowed'])
                @php($softRemoval = $deleteEligibility[$organisation->id]['mode'] === 'soft')
                <form method="POST" action="{{ route('organisations.destroy', $organisation) }}" onsubmit="return confirm(@js(__($softRemoval ? 'messages.confirm_organisation_removal' : 'messages.confirm_permanent_delete')))" >
                    @csrf @method('DELETE')
                    <button class="btn danger" type="submit">{{ __($softRemoval ? 'messages.remove_from_active_system' : 'messages.delete_permanently') }}</button>
                </form>
            @else
                <span title="{{ $deleteEligibility[$organisation->id]['reason'] }}">{{ __('messages.delete_not_allowed') }}</span>
            @endif
        </div>
        @if(!$deleteEligibility[$organisation->id]['allowed'])<small>{{ $deleteEligibility[$organisation->id]['reason'] }}</small>@endif
    </td></tr>
@endforeach
</tbody></table></div>
@endsection
