<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('messages.product_name'))</title>
    <script>
        (() => {
            const mode = localStorage.getItem('pqs-theme') || 'system';
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            document.documentElement.dataset.themeMode = mode;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body min-h-screen">
<header class="app-header">
    <div class="app-shell">
        <a class="app-brand" href="{{ auth()->check() ? (auth()->user()->isDoctorOnly() ? route('doctor.dashboard') : route('dashboard')) : route('login') }}"><span class="brand-mark" aria-hidden="true">+</span><span>{{ __('messages.product_name') }}</span></a>
        <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="app-navigation" aria-label="{{ __('messages.menu') }}"><span></span><span></span><span></span></button>
        <nav id="app-navigation" class="app-nav" aria-label="Main navigation">
            <div class="app-nav-links">
            @auth
                @if(auth()->user()->isDoctorOnly())
                    <a href="{{ route('doctor.dashboard') }}" @if(request()->routeIs('doctor.dashboard')) aria-current="page" @endif>{{ __('messages.doctor_dashboard') }}</a>
                @else
                    <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>{{ __('messages.dashboard') }}</a>
                    @if(auth()->user()->hasDoctorWorkspace())<a href="{{ route('doctor.dashboard') }}" @if(request()->routeIs('doctor.dashboard')) aria-current="page" @endif>{{ __('messages.doctor_dashboard') }}</a>@endif
                @endif
                @if(auth()->user()->canAdministerSystem())<a href="{{ route('organisations.index') }}" @if(request()->routeIs('organisations.*')) aria-current="page" @endif>{{ __('messages.organisations') }}</a><a href="{{ route('system.users') }}" @if(request()->routeIs('system.users*')) aria-current="page" @endif>{{ __('messages.users') }}</a><a href="{{ route('system.roles') }}" @if(request()->routeIs('system.roles*')) aria-current="page" @endif>{{ __('messages.roles') }}</a><a href="{{ route('audit.system') }}" @if(request()->routeIs('audit.*')) aria-current="page" @endif>{{ __('messages.audit') }}</a>@endif
                @if(auth()->user()->canReceiveAnonymizedResults())<a href="{{ route('anonymized-results.index') }}" @if(request()->routeIs('anonymized-results.*')) aria-current="page" @endif>{{ __('messages.anonymized_results') }}</a>@endif
            @endauth
            <a href="{{ route('about') }}" @if(request()->routeIs('about')) aria-current="page" @endif>{{ __('messages.about') }}</a>
            </div>
            <div class="app-nav-meta">
                @auth
                    <span class="user-pill"><span class="user-dot" aria-hidden="true"></span>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="link-button" type="submit">{{ __('messages.logout') }}</button></form>
                @else
                    <a class="btn btn-compact" href="{{ route('login') }}">{{ __('messages.login') }}</a>
                @endauth
            <div class="language-switcher" aria-label="{{ __('messages.language') }}">
                @foreach(config('form_locales.supported') as $code)<form method="POST" action="{{ route('locale',$code) }}" data-locale-form data-locale="{{ $code }}">@csrf<button class="rounded px-2 py-1 {{ app()->getLocale()===$code?'bg-indigo-100 font-semibold':'' }}">{{ strtoupper($code) }}</button></form>@endforeach
            </div>
            <label class="theme-control"><span class="sr-only">{{ __('messages.appearance') }}</span><select data-theme-select aria-label="{{ __('messages.appearance') }}"><option value="system">{{ __('messages.theme_system') }}</option><option value="light">{{ __('messages.theme_light') }}</option><option value="dark">{{ __('messages.theme_dark') }}</option></select></label>
            </div>
        </nav>
    </div>
</header>
<main class="app-main mx-auto max-w-7xl px-4 py-8">
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if(session('invitation_url'))<div class="notice success"><strong>{{ __('messages.invitation_url') }}:</strong> <code class="break-all">{{ session('invitation_url') }}</code></div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>{{ __('messages.validation_errors') }}</strong><ul class="list-disc pl-6">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
