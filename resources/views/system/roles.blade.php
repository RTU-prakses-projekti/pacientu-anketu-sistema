@extends('layouts.app')
@section('content')
<div class="page-header"><h1>{{ __('messages.roles_permissions') }}</h1><a class="btn" href="{{ route('system.users') }}">{{ __('messages.system_users') }}</a></div>
<div class="stack">@foreach($roles as $role)<form method="POST" action="{{ route('system.roles.update',$role) }}" class="card">@csrf @method('PUT')<h2>{{ $role->name }}</h2><p class="help">{{ $role->scope }}</p><div class="grid-cards">@foreach($permissions as $permission)<label class="choice"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains($permission))> {{ $permission->name }}</label>@endforeach</div><button class="btn primary mt-4">{{ __('messages.save') }}</button></form>@endforeach</div>
@endsection
