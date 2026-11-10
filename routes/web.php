<?php

use App\Http\Controllers\GoogleCalendarOAuthCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/google-calendar/oauth/callback', GoogleCalendarOAuthCallbackController::class);
