<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('messages.app_name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <a class="text-lg font-bold text-indigo-700" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ __('messages.app_name') }}</a>
        <nav class="flex flex-wrap items-center gap-3 text-sm" aria-label="Main navigation">
            @auth
                <a href="{{ route('dashboard') }}">{{ __('messages.dashboard') }}</a>
                @if(auth()->user()->isPlatformAdmin())<a href="{{ route('organisations.index') }}">{{ __('messages.organisations') }}</a><a href="{{ route('system.users') }}">{{ __('messages.users') }}</a><a href="{{ route('system.roles') }}">{{ __('messages.roles') }}</a><a href="{{ route('audit.system') }}">{{ __('messages.audit') }}</a>@endif
                <span class="text-slate-500">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="link-button">{{ __('messages.logout') }}</button></form>
            @endauth
            @guest<a href="{{ route('login') }}">{{ __('messages.login') }}</a><a href="{{ route('register') }}">{{ __('messages.register') }}</a>@endguest
            <div class="flex gap-1" aria-label="{{ __('messages.language') }}">
                @foreach(config('form_locales.supported') as $code)<form method="POST" action="{{ route('locale',$code) }}" data-locale-form data-locale="{{ $code }}">@csrf<button class="rounded px-2 py-1 {{ app()->getLocale()===$code?'bg-indigo-100 font-semibold':'' }}">{{ strtoupper($code) }}</button></form>@endforeach
            </div>
        </nav>
    </div>
</header>
<main class="mx-auto max-w-7xl px-4 py-8">
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if(session('invitation_url'))<div class="notice success"><strong>{{ __('messages.invitation_url') }}:</strong> <code class="break-all">{{ session('invitation_url') }}</code></div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>{{ __('messages.validation_errors') }}</strong><ul class="list-disc pl-6">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer class="mx-auto max-w-7xl px-4 py-8 text-xs text-slate-500"><p>{{ __('messages.security_warning') }}</p></footer>
@stack('scripts')
</body>
</html>
