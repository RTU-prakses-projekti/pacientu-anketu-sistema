@extends('layouts.app')
@section('title', __('messages.login'))
@section('content')
<div class="auth-card"><h1>{{ __('messages.login') }}</h1><form method="POST" action="{{ url('/login') }}" class="stack">@csrf
<label>{{ __('messages.email') }}<input name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus></label>
<label>{{ __('messages.password') }}<input name="password" type="password" autocomplete="current-password" required></label>
<button class="btn primary">{{ __('messages.login') }}</button></form></div>
@endsection
