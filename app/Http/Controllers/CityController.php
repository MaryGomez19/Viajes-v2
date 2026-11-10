<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;

class CityController extends Controller
{
    // 🚀 NUEVO: Regresa la lista de todas las ciudades a React
    public function index()
    {
        $cities = City::query()
            ->select(['id', 'name', 'country', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();

        return response()->json($cities, 200);
    }

    public function store(Request $request)
    {
        if ($request->filled('pais') && ! $request->filled('country')) {
            $request->merge(['country' => $request->input('pais')]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'country' => 'required|string|max:100',
        ]);

        $name = trim($validated['name']);
        $country = trim($validated['country']) ?: 'México';

        $alreadyExists = City::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->whereRaw('LOWER(TRIM(country)) = ?', [mb_strtolower($country)])
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'message' => 'Ya existe un destino con ese nombre y país.',
                'errors' => [
                    'name' => ['Ya existe un destino registrado como "' . $name . '" en ' . $country . '.'],
                ],
            ], 422);
        }

        $city = City::create([
            'name' => $name,
            'country' => $country,
        ]);

        return response()->json([
            'message' => '¡Ciudad agregada con éxito!',
            'city' => $city
        ], 201);
    }
}