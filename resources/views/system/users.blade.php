@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.system_users') }}</h1><a class="btn" href="{{ route('system.roles') }}">{{ __('messages.roles_permissions') }}</a></div>
<div class="card overflow-x-auto"><table><thead><tr><th>{{ __('messages.name') }}</th><th>{{ __('messages.email') }}</th><th>{{ __('messages.roles') }}</th><th>{{ __('messages.status') }}</th><th>{{ __('messages.actions') }}</th></tr></thead><tbody>@foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->globalRoles->pluck('name')->join(', ') ?: '—' }}</td><td>{{ $user->is_active ? __('messages.active') : __('messages.inactive') }}</td><td>@unless(auth()->user()->is($user))<form method="POST" action="{{ route('users.toggle',$user) }}">@csrf<button class="btn">{{ $user->is_active ? __('messages.disable') : __('messages.enable') }}</button></form>@endunless</td></tr>@endforeach</tbody></table></div>
{{ $users->links() }}
@endsection
