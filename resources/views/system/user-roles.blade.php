@extends('layouts.app')
@section('content')
<div class="page-header"><div><h1>{{ __('messages.change_roles') }}</h1><p>{{ $managedUser->name }} · {{ $managedUser->email }}</p></div><a class="btn" href="{{ route('system.users') }}">{{ __('messages.back') }}</a></div>
<form method="POST" action="{{ route('system.users.roles.update', $managedUser) }}" class="stack">@csrf @method('PUT')
    <section class="card">
        <h2>{{ __('messages.global_roles') }}</h2>
        <div class="grid-cards">
            @foreach($globalRoles as $role)<label class="choice"><input type="checkbox" name="global_roles[]" value="{{ $role->id }}" @checked($managedUser->globalRoles->contains($role))> {{ $role->display_name }}</label>@endforeach
        </div>
    </section>
    <section class="card">
        <h2>{{ __('messages.organisation_roles') }}</h2>
        <div class="stack">
            @forelse($organisations as $organisation)
                @php($membership = $managedUser->memberships->firstWhere('organisation_id', $organisation->id))
                <fieldset class="card"><legend><strong>{{ $organisation->name }}</strong></legend><div class="grid-cards">
                    @foreach($organisationRoles as $role)<label class="choice"><input type="checkbox" name="organisation_roles[{{ $organisation->id }}][]" value="{{ $role->id }}" @checked($membership?->is_active && $membership->roles->contains($role))> {{ $role->display_name }}</label>@endforeach
                </div></fieldset>
            @empty<p>{{ __('messages.no_records') }}</p>@endforelse
        </div>
    </section>
    <button class="btn primary" type="submit">{{ __('messages.save') }}</button>
</form>
@endsection
