@extends('layouts.app')
@section('title', __('messages.register'))
@section('content')
<div class="auth-card"><h1>{{ __('messages.create_account') }}</h1><form method="POST" action="{{ url('/register') }}" class="stack">@csrf
<label>{{ __('messages.name') }}<input name="name" value="{{ old('name') }}" required></label><label>{{ __('messages.email') }}<input name="email" type="email" value="{{ old('email') }}" required></label><label>{{ __('messages.student_id') }}<input name="student_id" value="{{ old('student_id') }}" required></label><label>{{ __('messages.password') }}<input name="password" type="password" autocomplete="new-password" required></label><label>{{ __('messages.password_confirmation') }}<input name="password_confirmation" type="password" autocomplete="new-password" required></label><button class="btn primary">{{ __('messages.register') }}</button></form><p class="mt-4">{{ __('messages.have_account') }} <a href="{{ route('login') }}">{{ __('messages.login') }}</a></p></div>
@endsection
