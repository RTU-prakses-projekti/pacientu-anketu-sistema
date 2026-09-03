@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.system_users') }}</h1><a class="btn" href="{{ route('system.roles') }}">{{ __('messages.roles_permissions') }}</a></div>

<form method="GET" action="{{ route('system.users') }}" class="card form-grid mb-6">
    <label>{{ __('messages.search') }}<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('messages.user_search_placeholder') }}"></label>
    <label>{{ __('messages.organisation') }}<select name="organisation"><option value="">{{ __('messages.all_organisations') }}</option>@foreach($organisations as $organisation)<option value="{{ $organisation->id }}" @selected((string)($filters['organisation'] ?? '') === (string)$organisation->id)>{{ $organisation->name }}</option>@endforeach</select></label>
    <label>{{ __('messages.role') }}<select name="role"><option value="">{{ __('messages.all_roles') }}</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string)($filters['role'] ?? '') === (string)$role->id)>{{ $role->label() }}</option>@endforeach</select></label>
    <label>{{ __('messages.status') }}<select name="status"><option value="">{{ __('messages.all_statuses') }}</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('messages.active') }}</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('messages.inactive') }}</option></select></label>
    <div class="actions"><button class="btn primary" type="submit">{{ __('messages.search') }}</button><a class="btn" href="{{ route('system.users') }}">{{ __('messages.clear_filters') }}</a></div>
</form>

<form method="POST" action="{{ route('system.users.store') }}" class="card form-grid mb-6">@csrf
    <h2>{{ __('messages.create_user') }}</h2>
    <label>{{ __('messages.name') }}<input name="name" required></label>
    <label>{{ __('messages.email') }}<input type="email" name="email" required></label>
    <label>{{ __('messages.password') }}<input type="password" name="password" required autocomplete="new-password"></label>
    <label>{{ __('messages.password_confirmation') }}<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <button class="btn primary">{{ __('messages.create_user') }}</button>
</form>
<div class="card table-wrap mobile-card-table"><table><thead><tr><th>ID</th><th>{{ __('messages.name') }}</th><th>{{ __('messages.email') }}</th><th>{{ __('messages.global_role') }}</th><th>{{ __('messages.organisations_roles') }}</th><th>{{ __('messages.status') }}</th><th>{{ __('messages.actions') }}</th></tr></thead><tbody>
@forelse($users as $user)
<tr><td data-label="ID">{{ $user->id }}</td><td data-label="{{ __('messages.name') }}">{{ $user->name }}</td><td data-label="{{ __('messages.email') }}">{{ $user->email }}</td><td data-label="{{ __('messages.global_role') }}">{{ $user->globalRoles->reject(fn ($role) => $role->name === 'platform_admin')->map(fn ($role) => $role->label())->join(', ') ?: '—' }}</td><td data-label="{{ __('messages.organisations_roles') }}">
    @php($activeMemberships = $user->memberships->where('is_active', true)->filter(fn ($membership) => $membership->organisation?->is_active && $membership->roles->isNotEmpty()))
    @forelse($activeMemberships as $membership)<div>{{ $membership->organisation->name }} — {{ $membership->roles->map(fn ($role) => $role->label())->join(', ') }}</div>@empty — @endforelse
</td><td data-label="{{ __('messages.status') }}">{{ $user->is_active ? __('messages.active') : __('messages.inactive') }}</td><td data-label="{{ __('messages.actions') }}"><div class="actions">
    <a class="btn" href="{{ route('system.users.roles.edit', $user) }}">{{ __('messages.change_roles') }}</a>
    @unless(auth()->user()->is($user))
        <form method="POST" action="{{ route('users.toggle', $user) }}">@csrf<button class="btn">{{ $user->is_active ? __('messages.disable') : __('messages.enable') }}</button></form>
        @if($deleteEligibility[$user->id]['allowed'])
            <form method="POST" action="{{ route('system.users.destroy', $user) }}" onsubmit="return confirm(@js(__('messages.confirm_permanent_delete')))" >@csrf @method('DELETE')<button class="btn danger" type="submit">{{ __('messages.delete_permanently') }}</button></form>
        @else
            <span title="{{ $deleteEligibility[$user->id]['reason'] }}">{{ __('messages.delete_not_allowed') }}</span>
        @endif
    @endunless
</div>@if(!$deleteEligibility[$user->id]['allowed'])<small>{{ $deleteEligibility[$user->id]['reason'] }}</small>@endif</td></tr>
@empty
<tr><td colspan="7">{{ __('messages.no_records') }}</td></tr>
@endforelse
</tbody></table></div>
{{ $users->links() }}
@endsection
