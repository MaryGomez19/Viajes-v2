<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class GoogleCalendarOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleCalendarService $googleCalendar)
    {
        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $returnPath = '/admin/fechas-importantes';

        if ($request->filled('error')) {
            return redirect($frontend . $returnPath . '?google=error&message=' . urlencode($request->query('error')));
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect($frontend . $returnPath . '?google=error&message=' . urlencode('Faltan parámetros de Google.'));
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
            $adminId = (int) ($payload['admin_id'] ?? 0);
            $admin = User::find($adminId);

            if (! $admin || ! $admin->hasRole('admin')) {
                throw new \RuntimeException('Administrador no válido.');
            }

            $googleCalendar->handleOAuthCallback($code, $state, $admin);

            return redirect($frontend . $returnPath . '?google=connected');
        } catch (\Throwable $e) {
            return redirect($frontend . $returnPath . '?google=error&message=' . urlencode($e->getMessage()));
        }
    }
}
