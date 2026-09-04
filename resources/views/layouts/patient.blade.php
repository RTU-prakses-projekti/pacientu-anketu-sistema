<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="referrer" content="no-referrer">
    <title>@yield('title', __('messages.patient_questionnaire_portal'))</title>
    <script>
        (() => { const mode = localStorage.getItem('pqs-theme') === 'dark' ? 'dark' : 'light'; localStorage.setItem('pqs-theme', mode); document.documentElement.dataset.theme = mode; document.documentElement.dataset.themeMode = mode; })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body patient-body min-h-screen">
<header class="app-header patient-header"><div class="app-shell patient-shell">
    <strong class="app-brand"><span class="brand-mark" aria-hidden="true">+</span><span>{{ __('messages.patient_questionnaire_portal') }}</span></strong>
    <div class="app-nav-meta"><div class="language-switcher" aria-label="{{ __('messages.language') }}">@foreach(config('form_locales.supported') as $code)<form method="POST" action="{{ route('locale',$code) }}" data-locale-form data-locale="{{ $code }}">@csrf<button class="rounded px-2 py-1 {{ app()->getLocale()===$code?'bg-indigo-100 font-semibold':'' }}">{{ strtoupper($code) }}</button></form>@endforeach</div><div class="theme-menu" data-theme-menu data-mode="light"><button class="theme-toggle" type="button" data-theme-toggle aria-expanded="false" aria-haspopup="menu" aria-label="{{ __('messages.appearance') }}"><svg class="theme-icon theme-icon-light" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg><svg class="theme-icon theme-icon-dark" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.3A8.5 8.5 0 0 1 8.7 4 8.5 8.5 0 1 0 20 15.3Z"/></svg></button><div class="theme-popover" data-theme-popover role="menu" hidden><strong>{{ __('messages.appearance') }}</strong><button type="button" role="menuitemradio" data-theme-choice="light">{{ __('messages.theme_light_short') }}</button><button type="button" role="menuitemradio" data-theme-choice="dark">{{ __('messages.theme_dark_short') }}</button></div></div></div>
</div></header>
<main class="app-main mx-auto max-w-4xl px-4 py-8">@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif @yield('content')</main>
@stack('scripts')
</body></html>
