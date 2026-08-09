<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="referrer" content="no-referrer">
    <title>@yield('title', __('messages.patient_questionnaire_portal'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<header class="border-b border-slate-200 bg-white"><div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3">
    <strong class="text-lg text-indigo-700">{{ __('messages.patient_questionnaire_portal') }}</strong>
    <div class="flex gap-1" aria-label="{{ __('messages.language') }}">@foreach(config('form_locales.supported') as $code)<form method="POST" action="{{ route('locale',$code) }}" data-locale-form data-locale="{{ $code }}">@csrf<button class="rounded px-2 py-1 {{ app()->getLocale()===$code?'bg-indigo-100 font-semibold':'' }}">{{ strtoupper($code) }}</button></form>@endforeach</div>
</div></header>
<main class="mx-auto max-w-4xl px-4 py-8">@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif @yield('content')</main>
@stack('scripts')
</body></html>
