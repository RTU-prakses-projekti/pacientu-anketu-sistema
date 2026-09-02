@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.roles_permissions') }}</h1><a class="btn" href="{{ route('system.users') }}">{{ __('messages.system_users') }}</a></div>

<form method="POST" action="{{ route('system.roles.store') }}" class="card mb-6">@csrf
    <h2>{{ __('messages.create_role') }}</h2>
    <p class="help">{{ __('messages.custom_role_scope_help') }}</p>
    <label>{{ __('messages.role_display_name') }}<input name="display_name" value="{{ old('display_name') }}" required maxlength="255"></label>
    <div class="grid-cards mt-4">
        @foreach($permissions as $permission)
            <label class="choice"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permissions', [])))> {{ $permission->name }}</label>
        @endforeach
    </div>
    <button class="btn primary mt-4">{{ __('messages.create_role') }}</button>
</form>

<div class="stack">
@foreach($roles as $role)
    <section class="card">
        <form method="POST" action="{{ route('system.roles.update', $role) }}">@csrf @method('PUT')
            <div class="page-header"><div><h2>{{ $role->label() }}</h2><p class="help"><code>{{ $role->name }}</code> · {{ $role->scope }} · {{ __($role->is_system ? 'messages.system_role' : 'messages.custom_role') }}</p></div></div>
            <div class="grid-cards">@foreach($permissions as $permission)<label class="choice"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains($permission))> {{ $permission->name }}</label>@endforeach</div>
            <button class="btn primary mt-4">{{ __('messages.save') }}</button>
        </form>
        @unless($role->is_system)
            <form method="POST" action="{{ route('system.roles.destroy', $role) }}" class="mt-4" data-custom-role-delete="{{ $role->id }}" onsubmit="return confirm(@js(__('messages.confirm_role_delete')))" >@csrf @method('DELETE')<button class="btn danger">{{ __('messages.delete') }}</button></form>
        @endunless
    </section>
@endforeach
</div>
@endsection
