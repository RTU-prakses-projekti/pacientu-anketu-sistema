<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="referrer" content="no-referrer">
    <title>@yield('title', __('messages.patient_questionnaire_portal'))</title>
    <script>
        (() => { const mode = localStorage.getItem('pqs-theme') || 'system'; const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches); document.documentElement.dataset.theme = dark ? 'dark' : 'light'; document.documentElement.dataset.themeMode = mode; })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body patient-body min-h-screen">
<header class="app-header patient-header"><div class="app-shell patient-shell">
    <strong class="app-brand"><span class="brand-mark" aria-hidden="true">+</span><span>{{ __('messages.patient_questionnaire_portal') }}</span></strong>
    <div class="app-nav-meta"><div class="language-switcher" aria-label="{{ __('messages.language') }}">@foreach(config('form_locales.supported') as $code)<form method="POST" action="{{ route('locale',$code) }}" data-locale-form data-locale="{{ $code }}">@csrf<button class="rounded px-2 py-1 {{ app()->getLocale()===$code?'bg-indigo-100 font-semibold':'' }}">{{ strtoupper($code) }}</button></form>@endforeach</div><label class="theme-control"><span class="sr-only">{{ __('messages.appearance') }}</span><select data-theme-select aria-label="{{ __('messages.appearance') }}"><option value="system">{{ __('messages.theme_system') }}</option><option value="light">{{ __('messages.theme_light') }}</option><option value="dark">{{ __('messages.theme_dark') }}</option></select></label></div>
</div></header>
<main class="app-main mx-auto max-w-4xl px-4 py-8">@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif @yield('content')</main>
@stack('scripts')
</body></html>
