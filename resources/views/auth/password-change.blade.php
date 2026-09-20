@extends('layouts.app')
@section('title', __('messages.change_password'))
@section('content')
<div class="auth-card">
    <h1 class="page-title">{{ __('messages.change_password') }}</h1>
    <p>{{ __('messages.password_change_required') }}</p>
    <form method="POST" action="{{ route('account.password.update') }}" class="stack">
        @csrf
        @method('PUT')
        <label>{{ __('messages.current_password') }}<input type="password" name="current_password" autocomplete="current-password" required autofocus></label>
        <label>{{ __('messages.new_password') }}<input type="password" name="password" autocomplete="new-password" required></label>
        <label>{{ __('messages.password_confirmation') }}<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
        <button class="btn primary">{{ __('messages.change_password') }}</button>
    </form>
</div>
@endsection
