<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role; // Importamos el modelo

class RoleController extends Controller
{
    public function store(Request $request)
    {
        // 1. Validamos que nos manden el nombre
        $request->validate([
            'name' => 'required|string|max:50|unique:roles',
            'description' => 'nullable|string|max:255',
        ]);

        // 2. Creamos el rol en la base de datos
        $role = Role::create([
            'name' => $request->name,
            'description' => $request->description
        ]);

        // 3. Devolvemos una respuesta en formato JSON
        return response()->json([
            'message' => '¡Rol creado exitosamente!',
            'role' => $role
        ], 201);
    }
}