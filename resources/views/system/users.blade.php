@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.system_users') }}</h1><a class="btn" href="{{ route('system.roles') }}">{{ __('messages.roles_permissions') }}</a></div>
<form method="POST" action="{{ route('system.platform-admins.store') }}" class="card form-grid mb-6">@csrf
    <h2>{{ __('messages.create_platform_admin') }}</h2>
    <label>{{ __('messages.name') }}<input name="name" required></label>
    <label>{{ __('messages.email') }}<input type="email" name="email" required></label>
    <label>{{ __('messages.password') }}<input type="password" name="password" required autocomplete="new-password"></label>
    <label>{{ __('messages.password_confirmation') }}<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <button class="btn primary">{{ __('messages.create_platform_admin') }}</button>
</form>
<div class="card overflow-x-auto"><table><thead><tr><th>{{ __('messages.name') }}</th><th>{{ __('messages.email') }}</th><th>{{ __('messages.roles') }}</th><th>{{ __('messages.status') }}</th><th>{{ __('messages.actions') }}</th></tr></thead><tbody>
@foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->globalRoles->pluck('name')->join(', ') ?: '—' }}</td><td>{{ $user->is_active ? __('messages.active') : __('messages.inactive') }}</td><td><div class="actions">
    @unless($user->isPlatformAdmin())<form method="POST" action="{{ route('system.platform-admins.promote',$user) }}" data-confirm="{{ __('messages.confirm_platform_admin_promotion') }}">@csrf<button class="btn">{{ __('messages.promote_platform_admin') }}</button></form>@endunless
    @unless(auth()->user()->is($user))<form method="POST" action="{{ route('users.toggle',$user) }}">@csrf<button class="btn">{{ $user->is_active ? __('messages.disable') : __('messages.enable') }}</button></form>@endunless
</div></td></tr>@endforeach
</tbody></table></div>
{{ $users->links() }}
@endsection
