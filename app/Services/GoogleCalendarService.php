<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\ImportantDate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';

    public function isConfigured(): bool
    {
        return filled(config('google_calendar.client_id'))
            && filled(config('google_calendar.client_secret'))
            && filled(config('google_calendar.redirect_uri'));
    }

    public function isConnected(): bool
    {
        $connection = GoogleCalendarConnection::agency();

        return $connection !== null && filled($connection->refresh_token);
    }

    public function connectionStatus(): array
    {
        $connection = GoogleCalendarConnection::agency();

        return [
            'configured' => $this->isConfigured(),
            'connected' => $this->isConnected(),
            'google_email' => $connection?->google_email,
            'calendar_id' => $connection?->calendar_id ?? 'primary',
            'connected_at' => $connection?->updated_at?->toIso8601String(),
        ];
    }

    public function createAuthorizationUrl(User $admin): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Google Calendar no está configurado en el servidor.');
        }

        $state = Crypt::encryptString(json_encode([
            'admin_id' => $admin->id,
            'nonce' => Str::random(32),
        ]));

        Cache::put('google_oauth_state:' . $admin->id, $state, now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => config('google_calendar.client_id'),
            'redirect_uri' => config('google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('google_calendar.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL . '?' . $query;
    }

    public function handleOAuthCallback(string $code, string $state, User $admin): GoogleCalendarConnection
    {
        $cached = Cache::get('google_oauth_state:' . $admin->id);
        if (! $cached || ! hash_equals($cached, $state)) {
            throw new \RuntimeException('Estado OAuth inválido o expirado.');
        }

        Cache::forget('google_oauth_state:' . $admin->id);

        $payload = json_decode(Crypt::decryptString($state), true);
        if (! is_array($payload) || (int) ($payload['admin_id'] ?? 0) !== (int) $admin->id) {
            throw new \RuntimeException('No se pudo validar la sesión del administrador.');
        }

        try {
            $response = $this->googleHttp()->asForm()->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => config('google_calendar.client_id'),
                'client_secret' => config('google_calendar.client_secret'),
                'redirect_uri' => config('google_calendar.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException $e) {
            $this->rethrowNetworkError($e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Google rechazó la autorización: ' . ($response->json('error_description') ?? 'error desconocido')
            );
        }

        $data = $response->json();
        $email = $this->fetchPrimaryEmail($data['access_token']);

        $connection = GoogleCalendarConnection::agency() ?? new GoogleCalendarConnection();
        $connection->fill([
            'google_email' => $email,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'calendar_id' => $connection->calendar_id ?: 'primary',
            'connected_by' => $admin->id,
        ]);
        $connection->save();

        return $connection;
    }

    public function disconnect(): void
    {
        GoogleCalendarConnection::query()->delete();
    }

    public function syncImportantDate(ImportantDate $date): ImportantDate
    {
        if (! $this->isConfigured()) {
            return $this->markSyncError($date, 'Faltan credenciales de Google Calendar en el servidor.');
        }

        if (! $this->isConnected()) {
            return $this->markSyncPending($date, 'Google Calendar no está conectado. Conecta la cuenta de la agencia.');
        }

        try {
            $connection = GoogleCalendarConnection::agency();
            $payload = $this->buildEventPayload($date);

            if ($date->google_calendar_event_id) {
                $response = $this->calendarRequest($connection)
                    ->patch(
                        self::CALENDAR_API . '/calendars/' . urlencode($connection->calendar_id)
                        . '/events/' . urlencode($date->google_calendar_event_id),
                        $payload
                    );
            } else {
                $response = $this->calendarRequest($connection)
                    ->post(
                        self::CALENDAR_API . '/calendars/' . urlencode($connection->calendar_id) . '/events',
                        $payload
                    );
            }

            if (! $response->successful()) {
                $message = $response->json('error.message') ?? $response->body();

                return $this->markSyncError($date, 'Google Calendar: ' . $message);
            }

            $eventId = $response->json('id');
            $date->google_calendar_event_id = $eventId ?: $date->google_calendar_event_id;
            $date->sync_status = 'synced';
            $date->sync_error = null;
            $date->synced_at = now();
            $date->save();

            return $date->fresh(['user', 'creator']);
        } catch (\Throwable $e) {
            return $this->markSyncError($date, $e->getMessage());
        }
    }

    public function deleteImportantDateEvent(ImportantDate $date): void
    {
        if (! $date->google_calendar_event_id || ! $this->isConnected()) {
            return;
        }

        try {
            $connection = GoogleCalendarConnection::agency();
            $this->calendarRequest($connection)
                ->delete(
                    self::CALENDAR_API . '/calendars/' . urlencode($connection->calendar_id)
                    . '/events/' . urlencode($date->google_calendar_event_id)
                );
        } catch (\Throwable) {
            // El registro local se elimina igual; el evento huérfano se puede limpiar manualmente.
        }
    }

    private function buildEventPayload(ImportantDate $date): array
    {
        $date->loadMissing('user');

        $eventDay = Carbon::parse($date->event_date)->format('Y-m-d');
        $nextDay = Carbon::parse($date->event_date)->addDay()->format('Y-m-d');

        $clientLine = $date->user
            ? trim($date->user->name . ' ' . ($date->user->last_name ?? ''))
            : ($date->type === 'informativo'
                ? 'Informativo · agencia'
                : 'Recordatorio de clientes');

        $description = trim(implode("\n\n", array_filter([
            $date->observation,
            'Cliente: ' . $clientLine,
            'Tipo: ' . $date->type,
            'Registrado en Viajes Sueña',
        ])));

        $minutes = $date->reminderMinutesBefore();

        $summary = $date->type === 'informativo'
            ? '[Informativo] '.$date->title
            : $date->title;

        $payload = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'date' => $eventDay,
                'timeZone' => config('google_calendar.timezone'),
            ],
            'end' => [
                'date' => $nextDay,
                'timeZone' => config('google_calendar.timezone'),
            ],
            'recurrence' => [
                'RRULE:FREQ=YEARLY',
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => array_values(array_filter([
                    ['method' => 'popup', 'minutes' => $minutes],
                    $minutes > 0 ? ['method' => 'email', 'minutes' => $minutes] : null,
                ])),
            ],
        ];

        if ($date->reminder_preset === 'custom' && $date->reminder_time) {
            $timeStr = Carbon::parse($date->reminder_time)->format('H:i');
            $payload['description'] .= "\n\nHora preferida de recordatorio: {$timeStr} (zona "
                . config('google_calendar.timezone') . ').';
        }

        return $payload;
    }

    private function markSyncPending(ImportantDate $date, string $message): ImportantDate
    {
        $date->sync_status = 'pending';
        $date->sync_error = $message;
        $date->save();

        return $date->fresh(['user', 'creator']);
    }

    private function markSyncError(ImportantDate $date, string $message): ImportantDate
    {
        $date->sync_status = 'error';
        $date->sync_error = $message;
        $date->save();

        return $date->fresh(['user', 'creator']);
    }

    private function calendarRequest(GoogleCalendarConnection $connection)
    {
        $token = $this->getValidAccessToken($connection);

        return $this->googleHttp()->withToken($token)->acceptJson();
    }

    private function getValidAccessToken(GoogleCalendarConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->isFuture() && $connection->access_token) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            throw new \RuntimeException('No hay refresh token de Google Calendar.');
        }

        try {
            $response = $this->googleHttp()->asForm()->post(self::TOKEN_URL, [
                'client_id' => config('google_calendar.client_id'),
                'client_secret' => config('google_calendar.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $e) {
            $this->rethrowNetworkError($e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                'No se pudo renovar el token de Google: ' . ($response->json('error_description') ?? 'error')
            );
        }

        $data = $response->json();
        $connection->access_token = $data['access_token'];
        $connection->expires_at = now()->addSeconds((int) ($data['expires_in'] ?? 3600));
        $connection->save();

        return $connection->access_token;
    }

    private function googleHttp(): PendingRequest
    {
        return Http::connectTimeout(20)->timeout(45);
    }

    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        try {
            $response = $this->googleHttp()
                ->withToken($accessToken)
                ->get('https://www.googleapis.com/oauth2/v2/userinfo');

            if (! $response->successful()) {
                return null;
            }

            return $response->json('email');
        } catch (ConnectionException) {
            return null;
        }
    }

    private function rethrowNetworkError(ConnectionException $e): void
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'cURL error 28') || str_contains($msg, 'Resolving timed out')) {
            throw new \RuntimeException(
                'El servidor no puede contactar a Google (DNS/red). En Windows, revisa que tu conexión principal '
                . 'tenga DNS válido (por ejemplo 8.8.8.8); a veces el adaptador VirtualBox (192.168.56.x) bloquea '
                . 'la resolución de nombres. Detalle técnico: ' . $msg,
                previous: $e
            );
        }

        throw $e;
    }
}
