<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale', $request->user()?->locale ?? 'lv');
        if (!in_array($locale, ['lv', 'en', 'ru'], true)) $locale = 'lv';
        app()->setLocale($locale);
        return $next($request);
    }
}
