@extends('layouts.app')
@section('title', __('messages.about'))
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="card">
        <h1>{{ __('messages.about') }}</h1>
        <p>{{ __('messages.about_description') }}</p>
        <p class="mt-4">{{ __('messages.about_system_additional') }}</p>
    </section>
    <section class="card">
        <h2>{{ __('messages.about_project') }}</h2>
        <p>{{ __('messages.about_project_description') }}</p>
    </section>
</div>
@endsection
