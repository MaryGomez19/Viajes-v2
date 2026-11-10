<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;

class GoogleCalendarController extends Controller
{
    public function __construct(
        private GoogleCalendarService $googleCalendar
    ) {}

    public function status()
    {
        return response()->json($this->googleCalendar->connectionStatus());
    }

    public function connectUrl(Request $request)
    {
        try {
            $url = $this->googleCalendar->createAuthorizationUrl($request->user());

            return response()->json(['url' => $url]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disconnect()
    {
        $this->googleCalendar->disconnect();

        return response()->json(['message' => 'Google Calendar desconectado.']);
    }
}
