<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (\Throwable) {
            return response()->json([
                'message' => 'No pudimos enviar el correo. Usa "Entrar con codigo SMS" o contacta a la agencia.',
            ], 503);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Demasiados intentos. Espera un minuto antes de volver a solicitar el enlace.',
            ], 429);
        }

        $payload = [
            'message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.',
        ];

        if (config('app.debug') && config('mail.default') === 'log') {
            $payload['dev_hint'] =
                'Modo desarrollo: revisa storage/logs/laravel.log para copiar el enlace de recuperación.';
        }

        return response()->json($payload);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = $password;
                $user->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Contraseña actualizada con éxito.',
            ]);
        }

        $messages = [
            Password::INVALID_TOKEN => 'El enlace no es válido o ha expirado.',
            Password::INVALID_USER => 'No encontramos una cuenta con ese correo.',
        ];

        return response()->json([
            'message' => $messages[$status] ?? 'No se pudo restablecer la contraseña.',
        ], 400);
    }
}
