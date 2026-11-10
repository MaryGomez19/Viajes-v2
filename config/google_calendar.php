<?php

return [
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env(
        'GOOGLE_CALENDAR_REDIRECT_URI',
        rtrim(env('APP_URL', 'http://localhost'), '/') . '/google-calendar/oauth/callback'
    ),
    'timezone' => env('GOOGLE_CALENDAR_TIMEZONE', 'America/Mexico_City'),
    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events',
    ],
];
