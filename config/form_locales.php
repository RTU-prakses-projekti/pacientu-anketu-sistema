<?php

return [
    'supported' => ['lv', 'en', 'ru'],
    'default' => 'lv',
    'fallback' => config('app.fallback_locale', 'en'),
];
