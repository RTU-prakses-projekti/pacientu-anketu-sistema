@extends('layouts.app')
@section('title', __('messages.about'))
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="card">
        <h1>{{ __('messages.about') }}</h1>
        <p>{{ __('messages.about_description') }}</p>
        <p class="mt-4">{{ __('messages.about_customisation') }}</p>
    </section>
    <section class="card">
        <h2>{{ __('messages.about_cta') }}</h2>
        <p>{{ __('messages.about_contact_text') }}</p>
        <a class="btn primary mt-4" href="mailto:ronalds.gigelis@gmail.com">ronalds.gigelis@gmail.com</a>
    </section>
</div>
@endsection
