<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\SmsService;
use App\Support\PhoneHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class PhoneOtpController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsService $sms) {}

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:100',
            'phone' => 'required|string|max:20',
        ]);

        $normalizedPhone = PhoneHelper::normalize($request->phone);

        if (!$normalizedPhone) {
            return response()->json([
                'message' => 'Ingresa un teléfono válido de 10 dígitos.',
            ], 422);
        }

        $user = $this->findUserByCredentials(
            $request->name,
            $request->email,
            $normalizedPhone
        );

        if (!$user) {
            return response()->json([
                'message' => 'Los datos no coinciden con una cuenta registrada. Revisa nombre, correo y teléfono.',
            ], 422);
        }

        if ($user->state !== 'active') {
            return response()->json([
                'message' => 'Tu cuenta no se encuentra activa en el sistema.',
            ], 403);
        }

        $code = (string) random_int(100000, 999999);
        $cacheKey = $this->cacheKey($normalizedPhone);

        Cache::put($cacheKey, [
            'hash' => Hash::make($code),
            'user_id' => $user->id,
            'attempts' => 0,
        ], now()->addMinutes(self::OTP_TTL_MINUTES));

        try {
            $this->sms->sendOtp($normalizedPhone, $code);
        } catch (\Throwable) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'No pudimos enviar el código. Intenta de nuevo en unos minutos.',
            ], 503);
        }

        $payload = [
            'message' => 'Datos verificados. Revisa tu SMS e ingresa el código.',
        ];

        if (config('app.debug') && !$this->sms->isConfigured()) {
            $payload['dev_hint'] = 'Modo desarrollo: el código aparece abajo (SMS no configurado).';
            $payload['dev_code'] = $code;
        }

        return response()->json($payload);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string|size:6',
        ]);

        $normalized = PhoneHelper::normalize($request->phone);

        if (!$normalized) {
            return response()->json([
                'message' => 'Teléfono inválido.',
            ], 422);
        }

        $cacheKey = $this->cacheKey($normalized);
        $stored = Cache::get($cacheKey);

        if (!$stored) {
            return response()->json([
                'message' => 'El código expiró o no fue solicitado. Pide uno nuevo.',
            ], 400);
        }

        if (($stored['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'Demasiados intentos. Solicita un código nuevo.',
            ], 429);
        }

        if (!Hash::check($request->code, $stored['hash'])) {
            $stored['attempts'] = ($stored['attempts'] ?? 0) + 1;
            Cache::put($cacheKey, $stored, now()->addMinutes(self::OTP_TTL_MINUTES));

            return response()->json([
                'message' => 'Código incorrecto.',
            ], 401);
        }

        $user = User::with('role')->find($stored['user_id']);

        if (!$user || $user->state !== 'active' || !$user->hasRole(Role::CLIENTE)) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'No se pudo iniciar sesión con este teléfono.',
            ], 403);
        }

        Cache::forget($cacheKey);

        $token = $user->createToken('sms_otp')->plainTextToken;

        return response()->json([
            'message' => 'Bienvenido de nuevo, ' . $user->name . '!',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->name,
            ],
        ]);
    }

    private function findUserByCredentials(
        string $name,
        string $email,
        string $normalizedPhone
    ): ?User {
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedName = $this->normalizeName($name);

        return User::query()
            ->with('role')
            ->whereNotNull('email')
            ->whereNotNull('phone')
            ->where('email', '!=', '')
            ->where('phone', '!=', '')
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->get()
            ->first(function (User $user) use ($normalizedName, $normalizedPhone) {
                if (!$user->hasRole(Role::CLIENTE)) {
                    return false;
                }

                if (PhoneHelper::normalize($user->phone) !== $normalizedPhone) {
                    return false;
                }

                return $this->nameMatches($user, $normalizedName);
            });
    }

    private function nameMatches(User $user, string $inputName): bool
    {
        $firstName = $this->normalizeName($user->name);
        $fullName = $this->normalizeName(trim($user->name . ' ' . $user->last_name));

        return $inputName === $fullName || $inputName === $firstName;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return $ascii !== false ? $ascii : $name;
    }

    private function cacheKey(string $normalizedPhone): string
    {
        return 'phone_otp:' . $normalizedPhone;
    }
}
