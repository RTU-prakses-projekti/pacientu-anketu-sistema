@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.roles_permissions') }}</h1><a class="btn" href="{{ route('system.users') }}">{{ __('messages.system_users') }}</a></div>

<form method="POST" action="{{ route('system.roles.store') }}" class="card mb-6">@csrf
    <h2>{{ __('messages.create_role') }}</h2>
    <p class="help">{{ __('messages.custom_role_scope_help') }}</p>
    <label>{{ __('messages.role_display_name') }}<input name="display_name" value="{{ old('display_name') }}" required maxlength="255"></label>
    @foreach($permissionGroups as $group)
        <fieldset class="card mt-4">
            <legend><strong>{{ $group['label'] }}</strong></legend>
            <div class="grid-cards">
                @foreach($group['permissions'] as $permission)
                    <label class="choice"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permissions', [])))> {{ __('messages.permission_'.$permission->name) }}</label>
                @endforeach
            </div>
            @if($group['key'] === 'doctor')<p class="help">{{ __('messages.doctor_permission_custom_help') }}</p>@endif
        </fieldset>
    @endforeach
    <button class="btn primary mt-4">{{ __('messages.create_role') }}</button>
</form>

<div class="stack">
@foreach($roles as $role)
    <section class="card">
        <form method="POST" action="{{ route('system.roles.update', $role) }}">@csrf @method('PUT')
            <div class="page-header"><div><h2>{{ $role->label() }}</h2><p class="help"><code>{{ $role->name }}</code> · {{ $role->scope }} · {{ __($role->is_system ? 'messages.system_role' : 'messages.custom_role') }}</p></div></div>
            @php($doctorPermissionsLocked = $role->is_system && $role->name !== 'doctor')
            @foreach($permissionGroups as $group)
                <fieldset class="card mt-4">
                    <legend><strong>{{ $group['label'] }}</strong></legend>
                    <div class="grid-cards">
                        @foreach($group['permissions'] as $permission)
                            <label class="choice"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains($permission)) @disabled($doctorPermissionsLocked && $group['key'] === 'doctor')> {{ __('messages.permission_'.$permission->name) }}</label>
                        @endforeach
                    </div>
                    @if($group['key'] === 'doctor' && $doctorPermissionsLocked)<p class="help">{{ __('messages.doctor_permission_system_help') }}</p>@elseif($group['key'] === 'doctor' && !$role->is_system)<p class="help">{{ __('messages.doctor_permission_custom_help') }}</p>@endif
                </fieldset>
            @endforeach
            <button class="btn primary mt-4">{{ __('messages.save') }}</button>
        </form>
        @unless($role->is_system)
            <form method="POST" action="{{ route('system.roles.destroy', $role) }}" class="mt-4" data-custom-role-delete="{{ $role->id }}" onsubmit="return confirm(@js(__('messages.confirm_role_delete')))" >@csrf @method('DELETE')<button class="btn danger">{{ __('messages.delete') }}</button></form>
        @endunless
    </section>
@endforeach
</div>
@endsection
