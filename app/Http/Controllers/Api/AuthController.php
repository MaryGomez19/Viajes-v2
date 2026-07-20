<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // 1. Registro exclusivo para la API
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:45',
            'last_name' => 'required|string|max:45',
            'email' => 'required|string|email|max:100|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = User::create([
            'name' => $request->name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'state' => 'active',
            'role_id' => 2,
        ]);

        return response()->json([
            'message' => '¡Usuario registrado con éxito! Revisa tu correo para verificar tu cuenta.',
            'user' => $user
        ], 201);
    }

    // 2. Login exclusivo para la API (Blindado contra redirecciones)
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'El correo y la contraseña son requeridos.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Las credenciales ingresadas son incorrectas.'
            ], 401);
        }

        if ($user->state !== 'active') {
            return response()->json([
                'message' => 'Tu cuenta no se encuentra activa en el sistema.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => '¡Bienvenido de nuevo, ' . $user->name . '!',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->name,
            ]
        ], 200);
    }
}