<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale', $request->user()?->locale ?? config('form_locales.default'));
        if (!in_array($locale, config('form_locales.supported'), true)) $locale = config('form_locales.default');
        app()->setLocale($locale);
        return $next($request);
    }
}
