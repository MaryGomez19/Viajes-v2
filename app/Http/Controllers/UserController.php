<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\PhoneHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $rolCliente = Role::findByKey(Role::CLIENTE);

        if (!$rolCliente) {
            return response()->json(['message' => 'Rol de cliente no configurado.'], 500);
        }

        $query = User::with(['role'])
            ->where('role_id', $rolCliente->id)
            ->withCount('reservations');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        if ($request->boolean('frequent')) {
            $query->where('is_frequent_client', true);
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->search, '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json(
            $query
                ->orderByDesc('is_frequent_client')
                ->orderBy('name')
                ->orderBy('last_name')
                ->paginate($perPage),
            200,
        );
    }

    public function toggleFrequent($id)
    {
        $rolCliente = Role::findByKey(Role::CLIENTE);

        if (!$rolCliente) {
            return response()->json(['message' => 'Rol de cliente no configurado.'], 500);
        }

        $user = User::find($id);

        if (!$user || (int) $user->role_id !== (int) $rolCliente->id) {
            return response()->json(['message' => 'Cliente no encontrado.'], 404);
        }

        $user->is_frequent_client = ! $user->is_frequent_client;
        $user->save();

        return response()->json([
            'message' => $user->is_frequent_client
                ? 'Marcado como cliente frecuente.'
                : 'Ya no está marcado como cliente frecuente.',
            'user' => $user->loadCount('reservations'),
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:45',
            'last_name' => 'required|string|max:45',
            'email' => 'nullable|string|email|max:100|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            'state' => 'sometimes|in:active,inactive,banned',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 400);
        }

        $user = User::create([
            'name' => $request->name,
            'last_name' => $request->last_name,
            'email' => $request->email ?: null,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'state' => $request->state ?? 'active',
            'role_id' => $request->role_id,
        ]);

        $message = '¡Cliente creado con éxito!';

        // 👇 Solo se manda invitación si hay correo real
        if ($user->email) {
            Password::sendResetLink(['email' => $user->email]);
            $message .= ' Se le envió un correo para establecer su contraseña.';
        } else {
            $message .= ' No tiene correo registrado, así que no podrá iniciar sesión en el portal hasta que se le agregue uno.';
        }

        return response()->json([
            'message' => $message,
            'user' => $user
        ], 201);
    }

    public function show($id)
    {
        $user = User::with(['role', 'packages'])->find($id);

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        return response()->json($user, 200);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:45',
            'last_name' => 'sometimes|string|max:45',
            'email' => 'nullable|string|email|max:100|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'sometimes|exists:roles,id',
            'state' => 'sometimes|in:active,inactive,banned',
            'is_frequent_client' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 400);
        }

        $emailAgregadoPorPrimeraVez = empty($user->email) && $request->filled('email');

        $user->name = $request->name ?? $user->name;
        $user->last_name = $request->last_name ?? $user->last_name;
        $user->email = $request->email ?? $user->email;
        $user->phone = $request->phone ?? $user->phone;
        $user->role_id = $request->role_id ?? $user->role_id;
        $user->state = $request->state ?? $user->state;

        if ($request->has('is_frequent_client')) {
            $user->is_frequent_client = $request->boolean('is_frequent_client');
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        $message = '¡Usuario actualizado con éxito!';

        // manda invitación si se agregó correo por primera vez y no se envió antes
        if ($emailAgregadoPorPrimeraVez) {
            Password::sendResetLink(['email' => $user->email]);
            $message .= ' Se le envió un correo para que establezca su contraseña de acceso.';
        }

        return response()->json([
            'message' => $message,
            'user' => $user
        ], 200);
    }


    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // Verificar reservas activas
        $reservasActivas = $user->reservations()
            ->whereIn('state', ['pending', 'confirmed', 'paid'])
            ->exists();

        if ($reservasActivas) {
            return response()->json([
                'message' => 'No se puede eliminar un usuario con reservas activas.'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => '¡Usuario eliminado definitivamente de la base de datos!'
        ], 200);
    }

    public function perfil(Request $request)
    {
        $user = $request->user()->load(['role', 'packages']);

        return response()->json($user, 200);
    }

    public function actualizarPerfil(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:45',
            'last_name' => 'sometimes|string|max:45',
            'email' => 'sometimes|string|email|max:100|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'phone_last4' => 'required_with:password|string|size:4|regex:/^\d{4}$/',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 400);
        }

        if ($request->filled('password')) {
            $normalizedPhone = PhoneHelper::normalize($user->phone);

            if (!$normalizedPhone) {
                return response()->json([
                    'message' => 'Errores de validación',
                    'errors' => [
                        'phone_last4' => ['No tienes teléfono registrado. Agrega uno en tu perfil primero.'],
                    ],
                ], 400);
            }

            if ($request->phone_last4 !== substr($normalizedPhone, -4)) {
                return response()->json([
                    'message' => 'Errores de validación',
                    'errors' => [
                        'phone_last4' => ['Los últimos 4 dígitos no coinciden con tu teléfono.'],
                    ],
                ], 400);
            }
        }

        $user->name = $request->name ?? $user->name;
        $user->last_name = $request->last_name ?? $user->last_name;
        $user->email = $request->email ?? $user->email;
        $user->phone = $request->phone ?? $user->phone;

        if ($request->filled('password')) {
            $user->password = $request->password;
        }

        $user->save();

        return response()->json([
            'message' => '¡Perfil actualizado con éxito!',
            'user' => $user
        ], 200);
    }
}
